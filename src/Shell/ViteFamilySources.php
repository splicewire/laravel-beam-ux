<?php

namespace Splicewire\Beam\Ux\Shell;

/** A conservative, byte-preserving edit for the Laravel Vite shape; unfamiliar syntax stays host-owned. */
class ViteFamilySources
{
    public const IMPORT = "import { familySources } from '@schemastud/seam/vite';";

    public const PLUGIN = 'familySources(),';

    /** @return array{status: 'wired'|'already'|'unreadable', contents: string} */
    public function wire(string $config): array
    {
        $unchanged = ['status' => 'unreadable', 'contents' => $config];
        $syntax = $this->syntax($config);
        if ($syntax === null) {
            return $unchanged;
        }

        $code = $syntax['code'];
        $imports = $this->imports($code, $syntax['literals'], $syntax['comments']);
        if ($imports === null) {
            return $unchanged;
        }
        $familyImports = array_filter($imports, function (array $import): bool {
            if ($import['module'] !== '@schemastud/seam/vite' || ! preg_match('/^\{(.*)\}$/', $import['bindings'], $named)) {
                return false;
            }

            return in_array('familySources', array_map('trim', explode(',', $named[1])), true);
        });
        $calls = $this->pluginCalls($code);
        if (count($familyImports) === 1 && $calls !== []) {
            // Other executable uses can shadow the imported binding; do not guess through them.
            preg_match_all('/\bfamilySources\b/', $code, $mentions, PREG_OFFSET_CAPTURE);
            $import = array_values($familyImports)[0];
            foreach ($mentions[0] as [, $offset]) {
                if (! in_array($offset, $calls, true) && ! ($offset >= $import['start'] && $offset < $import['end'])) {
                    return $unchanged;
                }
            }

            return ['status' => 'already', 'contents' => $config];
        }

        // A comment, unused import or uncertain existing wiring needs guidance, not a duplicate import.
        if (str_contains($config, 'familySources')) {
            return $unchanged;
        }

        $tailwindImports = array_filter($imports, fn (array $import): bool => $import['bindings'] === 'tailwindcss' && $import['module'] === '@tailwindcss/vite');
        if (count($tailwindImports) !== 1 || preg_match_all('/\btailwindcss\s*\(/', $code) !== 1
            || preg_match_all('/^([\t ]*)tailwindcss\(\),[\t ]*\r?$/m', $code, $anchors, PREG_OFFSET_CAPTURE) !== 1) {
            return $unchanged;
        }

        $lastImport = end($imports);
        $anchor = $anchors[0][0][1];
        $tailwindCall = $anchor + strlen($anchors[1][0][0]);
        if (! in_array($tailwindCall, $this->pluginCalls($code, 'tailwindcss'), true)
            || $lastImport === false || $lastImport['end'] > $anchor) {
            return $unchanged;
        }

        $ending = str_contains($config, "\r\n") ? "\r\n" : "\n";
        if (preg_match('/\G[\t ]*/', $config, $indent, 0, $anchor) !== 1) {
            return $unchanged;
        }
        $contents = substr_replace($config, $indent[0].self::PLUGIN.$ending, $anchor, 0);
        $contents = substr_replace($contents, self::IMPORT.$ending, $lastImport['end'], 0);

        return ['status' => 'wired', 'contents' => $contents];
    }

    /**
     * Mask non-executable text without moving any byte or newline. Literal metadata is used ONLY for import sources.
     * Regex literals and interpolated templates require a parser, so they take the unchanged fallback.
     *
     * @return array{code: string, literals: list<array{offset: int, end: int, value: string, quote: string}>, comments: list<array{start: int, end: int}>}|null
     */
    private function syntax(string $config): ?array
    {
        $code = $config;
        $literals = [];
        $comments = [];
        $length = strlen($config);
        for ($i = 0; $i < $length; $i++) {
            $start = $i;
            if (substr($config, $i, 2) === '//') {
                $end = strpos($config, "\n", $i + 2);
                $i = $end === false ? $length : $end;
            } elseif (substr($config, $i, 2) === '/*') {
                $end = strpos($config, '*/', $i + 2);
                if ($end === false) {
                    return null;
                }
                $i = $end + 2;
                $comments[] = ['start' => $start, 'end' => $i];
            } elseif (in_array($config[$i], ["'", '"', '`'], true)) {
                $quote = $config[$i++];
                while ($i < $length && $config[$i] !== $quote) {
                    if ($quote === '`' && substr($config, $i, 2) === '${') {
                        return null;
                    }
                    $i += $config[$i] === '\\' ? 2 : 1;
                }
                if ($i >= $length) {
                    return null;
                }
                $literals[] = ['offset' => $start, 'end' => $i + 1, 'value' => substr($config, $start + 1, $i - $start - 1), 'quote' => $quote];
                $i++;
            } elseif ($config[$i] === '/') {
                return null;
            } else {
                continue;
            }

            for ($j = $start; $j < $i; $j++) {
                if ($code[$j] !== "\r" && $code[$j] !== "\n") {
                    $code[$j] = ' ';
                }
            }
            $i--;
        }

        return ['code' => $code, 'literals' => $literals, 'comments' => $comments];
    }

    /**
     * @param  list<array{offset: int, end: int, value: string, quote: string}>  $literals
     * @param  list<array{start: int, end: int}>  $comments
     * @return list<array{bindings: string, module: string, start: int, end: int}>|null
     */
    private function imports(string $code, array $literals, array $comments): ?array
    {
        $imports = [];
        foreach ($literals as $literal) {
            $start = strrpos(substr($code, 0, $literal['offset']), "\n");
            $start = $start === false ? 0 : $start + 1;
            $end = strpos($code, "\n", $literal['end']);
            $end = $end === false ? strlen($code) : $end;
            $before = substr($code, $start, $literal['offset'] - $start);
            $after = substr($code, $literal['end'], $end - $literal['end']);
            if (! preg_match('/^import[\t ]+(.+?)[\t ]+from[\t ]+$/D', $before, $match)
                || ! preg_match('/^[\t ]*;?[\t ]*\r?$/D', $after)) {
                continue;
            }
            if ($literal['quote'] === '`' || str_contains($literal['value'], "\n")) {
                return null;
            }
            foreach ($comments as $comment) {
                if ($comment['start'] < $end && $comment['end'] > $end) {
                    // Inserting on this line boundary would put the new import inside a continuing comment.
                    return null;
                }
            }
            $imports[] = ['bindings' => trim($match[1]), 'module' => $literal['value'], 'start' => $start, 'end' => min($end + 1, strlen($code))];
        }

        return $imports;
    }

    /**
     * Direct plugin calls in the two measured forms: plugins: [...] and plugins: lazyPlugins(() => [...]).
     *
     * @return list<int> byte offsets of executable direct plugin call names
     */
    private function pluginCalls(string $code, string $name = 'familySources'): array
    {
        preg_match_all('/\bplugins\s*:\s*(?:lazyPlugins\s*\(\s*\(\s*\)\s*=>\s*)?\[/', $code, $arrays, PREG_OFFSET_CAPTURE);
        $calls = [];
        foreach ($arrays[0] as [$prefix, $offset]) {
            $open = $offset + strlen($prefix) - 1;
            $end = $this->closing($code, $open);
            if ($end === null) {
                continue;
            }
            $elementStart = true;
            for ($i = $open + 1; $i < $end; $i++) {
                if (ctype_space($code[$i])) {
                    continue;
                }
                if ($code[$i] === ',') {
                    $elementStart = true;

                    continue;
                }
                if ($elementStart && preg_match('/\G'.preg_quote($name, '/').'\s*\(/', $code, $call, 0, $i)) {
                    $paren = $i + strlen($call[0]) - 1;
                    $close = $this->closing($code, $paren);
                    if ($close !== null && preg_match('/\G\s*[,\]]/', $code, $after, 0, $close + 1)) {
                        $calls[] = $i;
                    }
                }
                $elementStart = false;
                if (str_contains('([{', $code[$i])) {
                    $close = $this->closing($code, $i);
                    if ($close === null) {
                        break;
                    }
                    $i = $close;
                }
            }
        }

        return array_values(array_unique($calls));
    }

    private function closing(string $code, int $open): ?int
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $expected = $pairs[$code[$open]] ?? null;
        if ($expected === null) {
            return null;
        }
        $stack = [$expected];
        for ($i = $open + 1, $length = strlen($code); $i < $length; $i++) {
            if (isset($pairs[$code[$i]])) {
                $stack[] = $pairs[$code[$i]];
            } elseif (str_contains(')]}', $code[$i])) {
                if (array_pop($stack) !== $code[$i]) {
                    return null;
                }
                if ($stack === []) {
                    return $i;
                }
            }
        }

        return null;
    }
}
