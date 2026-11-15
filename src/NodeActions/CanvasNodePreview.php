<?php

namespace Splicewire\Beam\Ux\NodeActions;

use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Splicewire\Beam\Ux\Bindings\CanvasBindings;
use Splicewire\Beam\Ux\Body\BodyKind;
use Splicewire\Beam\Ux\Data\CanvasBodyInputData;
use Splicewire\Beam\Ux\Data\EntryCanvasBindingsInputData;
use Splicewire\Beam\Ux\JsonDoc\CanvasBodyGuard;
use Splicewire\Beam\Ux\JsonDoc\JsonDocIdRematcher;
use Splicewire\Beam\Ux\JsonDoc\JsonNode;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Composition\NodeActions\NodeActionCandidateInputData;
use Splicewire\Composition\NodeActions\NodeActionCandidateProducer;
use Splicewire\Composition\NodeActions\NodeActionDecisionData;
use Splicewire\Composition\NodeActions\NodeActionMode;
use Splicewire\Composition\NodeActions\NodeActionRevisionData;
use Splicewire\Composition\NodeActions\NodeActionSnapshotData;
use Splicewire\Composition\NodeActions\NodeActionVerdictData;

/** A client-session preview: kept means offered. No Recorder or persistence is bound here. */
final class CanvasNodePreview
{
    public function __construct(private readonly NodeActionCandidateProducer $producer) {}

    /** @param list<array> $body @param list<string|int> $path */
    public function preview(BeamUxEntry $entry, mixed $actor, array $body, array $path, ?string $nodeId, NodeActionMode $mode, ?string $instruction): NodeActionDecisionData
    {
        app(CanvasBindings::class)->authorize($entry, $actor);
        if ($entry->bodyKind() !== BodyKind::Blocks) {
            throw \Splicewire\Beam\Localization\CodedValidationException::for('body', \Splicewire\Beam\Ux\Publish\EntryError::NodePreviewBlocksRequired);
        }
        EntryCanvasBindingsInputData::assertBounds(['body' => $body]);
        CanvasBodyInputData::validateAndCreate(['body' => $body]);
        if (count($path) > 64 || ($mode === NodeActionMode::Refine && trim($instruction ?? '') === '') || strlen($instruction ?? '') > 8000) {
            throw \Splicewire\Beam\Localization\CodedValidationException::for('instruction', \Splicewire\Beam\Ux\Publish\EntryError::NodePreviewInputInvalid);
        }
        $matches = [];
        $this->locate($body, [], $path, $nodeId, $matches);
        if (count($matches) !== 1) {
            throw \Splicewire\Beam\Localization\CodedValidationException::for('path', \Splicewire\Beam\Ux\Publish\EntryError::NodePreviewAddressInvalid);
        }
        [$selectedPath, $selected] = $matches[0];
        $address = $selected['id'] ?? '/'.implode('/', array_map(fn ($part) => str_replace(['~', '/'], ['~0', '~1'], (string) $part), $selectedPath));
        $before = new NodeActionSnapshotData($address, $selected, []);
        $candidate = $this->producer->produce(new NodeActionCandidateInputData(
            mode: $mode, before: $before,
            outputSchema: app(JsonSchemaGenerator::class)->forLlmStrict()->generate(new ReflectionClass(JsonNode::class)),
            instruction: $instruction,
            context: ['body' => $body, 'path' => $selectedPath, 'profile' => app(ManifestProfileResolver::class)->resolve($entry)],
        ))->truth;
        $reference = (string) \Illuminate\Support\Str::uuid7();
        try {
            EntryCanvasBindingsInputData::assertBounds(['body' => [$candidate]]);
            CanvasBodyInputData::validateAndCreate(['body' => [$candidate]]);
            $candidate = $this->preserveIds($selected, $candidate);
            $prospective = $body;
            $target = &$prospective;
            foreach ($selectedPath as $segment) {
                $target = &$target[$segment];
            }
            $target = $candidate;
            unset($target);
            app(CanvasBodyGuard::class)->assertAdmitted($entry, $prospective);
        } catch (ValidationException $error) {
            $reasons = array_values(array_filter(\Illuminate\Support\Arr::flatten($error->errors()), 'is_string'));

            return new NodeActionDecisionData('reverted', new NodeActionRevisionData($reference, $before, $before), new NodeActionVerdictData(true, false, $reasons));
        }

        return new NodeActionDecisionData('kept', new NodeActionRevisionData($reference, $before,
            new NodeActionSnapshotData($address, $candidate, [])), new NodeActionVerdictData(false, false));
    }

    /** Reserve the addressed root identity before matching descendants, so a new child cannot steal it. */
    private function preserveIds(array $before, array $candidate): array
    {
        $rematcher = app(JsonDocIdRematcher::class);
        if (($before['kind'] ?? null) !== 'block' || ($candidate['kind'] ?? null) !== 'block') {
            return $rematcher->rematch([$before], [$candidate])[0];
        }
        $oldChildren = [...\Splicewire\Beam\Ux\JsonDoc\NodeChildren::of($before), ...($before['repeat']['empty'] ?? [])];
        $groups = [['children']];
        foreach ($candidate['slots'] ?? [] as $name => $nodes) {
            $groups[] = ['slots', $name];
        }
        if (isset($candidate['repeat']['empty'])) {
            $groups[] = ['repeat', 'empty'];
        }
        $parsed = [];
        $sizes = [];
        foreach ($groups as $path) {
            $nodes = $candidate;
            foreach ($path as $segment) {
                $nodes = $nodes[$segment] ?? [];
            }
            $sizes[] = count($nodes);
            array_push($parsed, ...$nodes);
        }
        $matched = $rematcher->rematch($oldChildren, $parsed);
        $offset = 0;
        foreach ($groups as $index => $path) {
            $target = &$candidate;
            foreach ($path as $segment) {
                $target = &$target[$segment];
            }
            $target = array_slice($matched, $offset, $sizes[$index]);
            unset($target);
            $offset += $sizes[$index];
        }
        $candidate['id'] = $before['id'] ?? (string) \Illuminate\Support\Str::uuid7();

        return $candidate;
    }

    /** Walk node positions only; a caller cannot address props or another arbitrary object as a node. */
    private function locate(array $nodes, array $prefix, array $path, ?string $nodeId, array &$matches): void
    {
        if (count($prefix) > 64) {
            throw \Splicewire\Beam\Localization\CodedValidationException::for('body', \Splicewire\Beam\Ux\Publish\EntryError::NodePreviewDepthExceeded);
        }
        foreach ($nodes as $index => $node) {
            $here = [...$prefix, $index];
            if ($nodeId !== null ? ($node['id'] ?? null) === $nodeId : $here === $path) {
                $matches[] = [$here, $node];
            }
            $this->locate($node['children'] ?? [], [...$here, 'children'], $path, $nodeId, $matches);
            foreach ($node['slots'] ?? [] as $name => $slot) {
                $this->locate($slot, [...$here, 'slots', $name], $path, $nodeId, $matches);
            }
            $this->locate($node['repeat']['empty'] ?? [], [...$here, 'repeat', 'empty'], $path, $nodeId, $matches);
        }
    }
}
