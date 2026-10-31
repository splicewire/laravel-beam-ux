<?php

namespace Splicewire\Beam\Ux\Bindings;

/** Exact node + structural location + enclosing binding context, including idless text nodes. */
final class BindingNodeBaseline
{
    private array $nodes = [];

    public function __construct(array $body)
    {
        self::visit($body, function (array $node, string $path, array $context): void {
            $this->nodes[$path] = self::canonical([$node, $context]);
        });
    }

    public function unchanged(array $node, string $path, array $context): bool
    {
        return isset($this->nodes[$path]) && $this->nodes[$path] === self::canonical([$node, $context]);
    }

    private static function canonical(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING); // JSON object ordering is not a structural edit; list ordering is.
        }

        return $value;
    }

    /** Same location vocabulary for admission and diagnostics. Slots and empty branches never collapse. */
    public static function visit(array $nodes, callable $visit, string $path = '', array $context = []): void
    {
        foreach ($nodes as $index => $node) {
            $at = $path.'/'.$index;
            $visit($node, $at, $context);
            $inner = $context;
            if (isset($node['repeat'])) {
                $repeat = $node['repeat'];
                unset($repeat['empty']);
                $inner[] = ['repeat' => $repeat];
                self::visit($node['repeat']['empty'] ?? [], $visit, $at.'/repeat/empty', $context);
            }
            if (isset($node['when'])) {
                $inner[] = ['when' => $node['when']];
            }
            self::visit($node['children'] ?? [], $visit, $at.'/children', $inner);
            foreach ($node['slots'] ?? [] as $name => $children) {
                self::visit($children, $visit, $at.'/slots/'.rawurlencode($name), $inner);
            }
        }
    }
}
