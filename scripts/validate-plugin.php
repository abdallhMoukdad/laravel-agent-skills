<?php

/**
 * Static validator for the laravel-agent-skills plugin.
 *
 * Checks every skill for:
 *   - SKILL.md frontmatter completeness (name, description, version)
 *   - description has at least 5 quoted trigger phrases
 *   - SKILL.md word count <= 2000
 *   - "Additional Resources" section lists references files
 *   - every referenced file exists on disk
 *   - every references/*.md file is referenced by SKILL.md
 *
 * Exits 0 on success, 1 on any failure.
 *
 * Usage: php scripts/validate-plugin.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$skillsDir = $root . '/skills';

if (!is_dir($skillsDir)) {
    fwrite(STDERR, "ERROR: skills/ directory not found at {$skillsDir}\n");
    exit(1);
}

$errors = [];
$warnings = [];
$skills = array_filter(scandir($skillsDir), fn ($e) => is_dir($skillsDir . '/' . $e) && !str_starts_with($e, '.'));

echo "Validating " . count($skills) . " skills...\n\n";

foreach ($skills as $skill) {
    $skillPath = "{$skillsDir}/{$skill}";
    $skillMd = "{$skillPath}/SKILL.md";
    $referencesDir = "{$skillPath}/references";

    if (!is_file($skillMd)) {
        $errors[] = "{$skill}: SKILL.md missing";
        continue;
    }

    $contents = file_get_contents($skillMd);

    // Frontmatter check
    if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $contents, $m)) {
        $errors[] = "{$skill}: SKILL.md is missing YAML frontmatter (---...---)";
        continue;
    }
    $frontmatter = $m[1];
    $body = $m[2];

    foreach (['name', 'description', 'version'] as $key) {
        if (!preg_match('/^\s*' . $key . '\s*:/m', $frontmatter)) {
            $errors[] = "{$skill}: frontmatter missing '{$key}'";
        }
    }

    // Name should match directory
    if (preg_match('/^\s*name\s*:\s*(\S+)/m', $frontmatter, $nm)) {
        if (trim($nm[1]) !== $skill) {
            $errors[] = "{$skill}: frontmatter name '{$nm[1]}' does not match directory name '{$skill}'";
        }
    }

    // Trigger phrases — count quoted phrases in description
    if (preg_match('/^\s*description\s*:\s*(.+?)(?=\n\S|\n---|$)/ms', $frontmatter, $dm)) {
        $description = $dm[1];
        $triggerCount = preg_match_all('/"[^"]+"/', $description);
        if ($triggerCount < 5) {
            $errors[] = "{$skill}: description has only {$triggerCount} quoted trigger phrase(s); need >= 5";
        }
    }

    // Word count <= 2000 (body only, not frontmatter)
    // Use whitespace-split counting (matches `wc -w`) — str_word_count
    // undercounts because it treats `Cache::remember()` as zero words.
    $wordCount = count(preg_split('/\s+/', trim($body), -1, PREG_SPLIT_NO_EMPTY));
    if ($wordCount > 2000) {
        $errors[] = "{$skill}: SKILL.md body has {$wordCount} words (limit: 2000)";
    } elseif ($wordCount > 1800) {
        $warnings[] = "{$skill}: SKILL.md body at {$wordCount} words — close to 2000 limit";
    }

    // Additional Resources section
    if (!str_contains($body, '## Additional Resources')) {
        $errors[] = "{$skill}: SKILL.md missing '## Additional Resources' section";
    }

    // Find references mentioned in SKILL.md
    preg_match_all('|references/([\w\-]+\.md)|', $body, $refMatches);
    $referencedFiles = array_unique($refMatches[1] ?? []);

    // Find references files on disk
    $diskFiles = [];
    if (is_dir($referencesDir)) {
        $diskFiles = array_values(array_filter(
            scandir($referencesDir),
            fn ($f) => str_ends_with($f, '.md')
        ));
    }

    // Each referenced file must exist
    foreach ($referencedFiles as $ref) {
        if (!in_array($ref, $diskFiles, true)) {
            $errors[] = "{$skill}: SKILL.md references 'references/{$ref}' but file does not exist";
        }
    }

    // Each disk file must be referenced
    foreach ($diskFiles as $file) {
        if (!in_array($file, $referencedFiles, true)) {
            $warnings[] = "{$skill}: 'references/{$file}' exists on disk but is not linked from SKILL.md";
        }
    }

    // Every references/*.md file must have content
    foreach ($diskFiles as $file) {
        $size = filesize("{$referencesDir}/{$file}");
        if ($size < 200) {
            $errors[] = "{$skill}: references/{$file} is suspiciously small ({$size} bytes) — looks like a stub";
        }
    }

    echo "  ✓ {$skill} ({$wordCount} words, " . count($diskFiles) . " references)\n";
}

// Plugin manifest checks
$pluginJson = "{$root}/.claude-plugin/plugin.json";
$marketplaceJson = "{$root}/.claude-plugin/marketplace.json";

if (!is_file($pluginJson)) {
    $errors[] = ".claude-plugin/plugin.json missing";
} else {
    $data = json_decode(file_get_contents($pluginJson), true);
    foreach (['name', 'version', 'description'] as $key) {
        if (!isset($data[$key])) {
            $errors[] = ".claude-plugin/plugin.json missing '{$key}'";
        }
    }
}

if (!is_file($marketplaceJson)) {
    $errors[] = ".claude-plugin/marketplace.json missing";
} else {
    $data = json_decode(file_get_contents($marketplaceJson), true);
    foreach (['name', 'owner', 'plugins'] as $key) {
        if (!isset($data[$key])) {
            $errors[] = ".claude-plugin/marketplace.json missing '{$key}'";
        }
    }
}

echo "\n";
if ($warnings) {
    echo "Warnings (" . count($warnings) . "):\n";
    foreach ($warnings as $w) {
        echo "  ⚠ {$w}\n";
    }
    echo "\n";
}

if ($errors) {
    echo "Errors (" . count($errors) . "):\n";
    foreach ($errors as $e) {
        echo "  ✗ {$e}\n";
    }
    echo "\nFAIL\n";
    exit(1);
}

echo "OK — all " . count($skills) . " skills valid.\n";
exit(0);
