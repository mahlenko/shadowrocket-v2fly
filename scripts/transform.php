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
    private array $cache      = []; // name => all rules (без фильтра)
    private array $tags_cache = []; // name => ['tag' => [rules]]

    public function __construct(private string $inputDir) {}

    /**
     * @param string      $name  имя файла в inputDir
     * @param string|null $tag   если задан — вернуть только правила с этим тегом
     */
    public function transformFile(string $name, ?string $tag = null): array
    {
        $this->parseFile($name);

        if ($tag !== null) {
            return $this->tags_cache[$name][$tag] ?? [];
        }

        return $this->cache[$name] ?? [];
    }

    private function parseFile(string $name): void
    {
        // Уже распарсили
        if (array_key_exists($name, $this->cache)) {
            return;
        }

        // Защита от циклических include
        $this->cache[$name]      = [];
        $this->tags_cache[$name] = [];

        $file = $this->inputDir . '/' . $name;
        if (!file_exists($file)) {
            fwrite(STDERR, "Warning: include target '$name' not found\n");
            return;
        }

        $lines     = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $allRules  = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Извлекаем все теги (@ads, @cn, ...) до удаления
            preg_match_all('/@(\S+)/', $line, $matches);
            $lineTags = $matches[1]; // ['ads', 'cn', ...]

            // Убираем теги из строки
            $clean = trim(preg_replace('/@\S+/', '', $line));

            if ($clean === '') {
                continue;
            }

            // include с тегом: "include:adobe @ads"
            if (str_starts_with($clean, 'include:')) {
                $includeName = substr($clean, 8);

                if (empty($lineTags)) {
                    // include без тега — берём весь файл
                    $included = $this->transformFile($includeName);
                    array_push($allRules, ...$included);
                } else {
                    // include с тегом — берём только строки с этим тегом
                    foreach ($lineTags as $includeTag) {
                        $included = $this->transformFile($includeName, $includeTag);
                        array_push($allRules, ...$included);

                        // Пробрасываем тег дальше (чтобы родитель мог фильтровать)
                        foreach ($included as $rule) {
                            $this->tags_cache[$name][$includeTag][] = $rule;
                        }
                    }
                }
                continue;
            }

            $rule = $this->transformRule($clean);
            if ($rule === null) {
                continue;
            }

            $allRules[] = $rule;

            // Индексируем по тегам
            foreach ($lineTags as $t) {
                $this->tags_cache[$name][$t][] = $rule;
            }
        }

        $this->cache[$name] = $allRules;
    }

    private function transformRule(string $line): ?string
    {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if ($line === '') {
            return null;
        }

        return match (true) {
            str_starts_with($line, 'domain:') => 'DOMAIN-SUFFIX,' . substr($line, 7),
            str_starts_with($line, 'full:')   => 'DOMAIN,'        . substr($line, 5),
            str_starts_with($line, 'regexp:') => 'URL-REGEX,'     . $this->convertRegex(substr($line, 7)),
            default                           => 'DOMAIN-SUFFIX,' . $line,
        };
    }

    private function convertRegex(string $goRegex): string
    {
        $pcre = str_replace(['\A', '\z'], ['^', '$'], $goRegex);
        $pcre = preg_replace('/\(\?P<(\w+)>/', '(?<$1>', $pcre);
        return $pcre;
    }
}

function buildHeader(string $name, array $rules): string
{
    $total        = count($rules);
    $domainSuffix = count(array_filter($rules, fn($r) => str_starts_with($r, 'DOMAIN-SUFFIX,')));
    $domain       = count(array_filter($rules, fn($r) => str_starts_with($r, 'DOMAIN,')));
    $urlRegex     = count(array_filter($rules, fn($r) => str_starts_with($r, 'URL-REGEX,')));
    $updated      = gmdate('Y-m-d H:i:s');

    $header  = '# NAME: ' . strtoupper($name) . "\n";
    $header .= "# AUTHOR: Sergey Makhlenko\n";
    $header .= "# REPO: https://github.com/mahlenko/shadowrocket-v2fly\n";
    $header .= "# UPDATED: {$updated}\n";

    if ($domainSuffix > 0) $header .= "# DOMAIN-SUFFIX: {$domainSuffix}\n";
    if ($domain > 0)       $header .= "# DOMAIN: {$domain}\n";
    if ($urlRegex > 0)     $header .= "# URL-REGEX: {$urlRegex}\n";

    $header .= "# TOTAL: {$total}\n";

    return $header;
}

// --- main ---

$files       = glob($inputDir . '/*');
$transformer = new DomainListTransformer($inputDir);
$count       = 0;

foreach ($files as $file) {
    $name = basename($file);

    $rules = $transformer->transformFile($name);

    if (empty($rules)) {
        continue;
    }

    $rules = array_values(array_unique($rules));

    usort($rules, function (string $a, string $b): int {
        $valA = explode(',', $a, 2)[1] ?? $a;
        $valB = explode(',', $b, 2)[1] ?? $b;
        return strcmp($valA, $valB);
    });

    $header  = buildHeader($name, $rules);
    $content = $header . implode("\n", $rules) . "\n";

    file_put_contents("$outputDir/$name.list", $content);
    $count++;
}

echo "Done: $count files written\n";
