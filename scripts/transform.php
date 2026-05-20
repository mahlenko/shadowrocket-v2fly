<?php
// scripts/transform.php
// Usage: php transform.php <input_dir> <output_dir>

declare(strict_types=1);

[$_, $inputDir, $outputDir] = $argv;

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

class DomainListTransformer
{
    private array $cache = [];

    public function __construct(private string $inputDir) {}

    public function transformFile(string $name): array {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        // Защита от циклических include
        $this->cache[$name] = [];

        $file = $this->inputDir.'/'.$name;
        if (!file_exists($file)) {
            fwrite(STDERR, "Warning: include target '$name' not found\n");
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $rules = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // ← ДОБАВЛЕНО: раскрываем include
            if (str_starts_with($line, 'include:')) {
                $includeName = substr($line, 8);
                array_push($rules, ...$this->transformFile($includeName));
                continue;
            }

            $rule = $this->transformRule($line);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        $this->cache[$name] = $rules;
        return $rules;
    }

    private function transformRule(string $line): ?string {
        // Убираем inline-комментарии, но оставляем @tags
        $line = trim(preg_replace('/#.*$/', '', $line));

        if ($line === '') {
            return null;
        }

        preg_match_all('/@(\S+)/', $line, $matches);
        $tags = $matches[1] ?? [];

        // Убираем теги из правила, но сохраним их позже как комментарий
        $line = trim(preg_replace('/@\S+/', '', $line));

        $rule = match (true) {
            str_starts_with($line, 'domain:') => 'DOMAIN-SUFFIX,'.substr($line, 7),
            str_starts_with($line, 'full:') => 'DOMAIN,'.substr($line, 5),
            str_starts_with($line, 'regexp:') => 'URL-REGEX,'.$this->convertRegex(substr($line, 7)),
            default => 'DOMAIN-SUFFIX,'.$line,
        };

        if ($tags !== []) {
            $rule .= ' #tags:'.implode(',', $tags);
        }

        return $rule;
    }

    private function convertRegex(string $goRegex): string {
        $pcre = $goRegex;
        $pcre = str_replace(['\A', '\z'], ['^', '$'], $pcre);
        $pcre = preg_replace('/\(\?P<(\w+)>/', '(?<$1>', $pcre);
        return $pcre;
    }
}

function buildHeader(string $name, array $rules): string {
    $total = count($rules);
    $domainSuffix = count(array_filter($rules, fn($r) => str_starts_with($r, 'DOMAIN-SUFFIX,')));
    $domain = count(array_filter($rules, fn($r) => str_starts_with($r, 'DOMAIN,')));
    $urlRegex = count(array_filter($rules, fn($r) => str_starts_with($r, 'URL-REGEX,')));
    $updated = gmdate('Y-m-d H:i:s');
    $listName = strtoupper($name);

    $header = "# NAME: {$listName}\n";
    $header .= "# AUTHOR: Sergey Makhlenko\n";
    $header .= "# REPO: https://github.com/mahlenko/shadowrocket-v2fly\n";
    $header .= "# UPDATED: {$updated}\n";

    if ($domainSuffix > 0) {
        $header .= "# DOMAIN-SUFFIX: {$domainSuffix}\n";
    }
    if ($domain > 0) {
        $header .= "# DOMAIN: {$domain}\n";
    }
    if ($urlRegex > 0) {
        $header .= "# URL-REGEX: {$urlRegex}\n";
    }

    $header .= "# TOTAL: {$total}\n";

    return $header;
}

// --- main ---

$files = glob($inputDir.'/*');
$transformer = new DomainListTransformer($inputDir);
$count = 0;

foreach ($files as $file) {
    $name = basename($file);
    $rules = $transformer->transformFile($name);

    if (in_array($name, ['category-ads', 'category-ads-all'], true)) {
        $rules = array_filter(
            $rules,
            fn(string $rule): bool => str_contains($rule, '#tags:ads')
        );
    }

    if (empty($rules)) {
        continue;
    }

    $rules = array_values(array_unique($rules));

    // Сортировка по алфавиту (по значению после первой запятой)
    usort($rules, function (string $a, string $b): int {
        $valA = explode(',', $a, 2)[1] ?? $a;
        $valB = explode(',', $b, 2)[1] ?? $b;
        return strcmp($valA, $valB);
    });

    // Убираем технические теги перед записью файла
    $rules = array_map(
        fn(string $r): string => preg_replace('/\s+#tags:.*$/', '', $r),
        $rules
    );

    $header = buildHeader($name, $rules);
    $content = $header.implode("\n", $rules)."\n";

    file_put_contents("$outputDir/$name.list", $content);
    $count++;
}

echo "Done: $count files written\n";
