<?php

declare(strict_types=1);

namespace FortyFives\Tests\Frontend;

use PHPUnit\Framework\TestCase;

final class XssInvariantsTest extends TestCase
{
    /** @return list<string> */
    private function frontendFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/public';
        return [$root . '/game.html', $root . '/lobby.html'];
    }

    public function testNoInnerHtmlAssignmentWithDynamicContent(): void
    {
        $violations = [];

        foreach ($this->frontendFiles() as $path) {
            $rel   = 'public/' . basename($path);
            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $line) {
                // Allow `elem.innerHTML = ''` and `elem.innerHTML = ""` (clearing).
                // Reject anything else: template literals, variables, concatenation.
                if (preg_match('/\.innerHTML\s*=\s*/', $line)
                    && !preg_match('/\.innerHTML\s*=\s*[\'"][\'"]/', $line)
                ) {
                    $violations[] = $rel . ':' . ($i + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Dynamic innerHTML assignments found (replace with safe DOM construction):\n"
                . implode("\n", $violations)
        );
    }

    public function testNoDangerousHtmlSinks(): void
    {
        $patterns = [
            '/insertAdjacentHTML\s*\(/'   => 'insertAdjacentHTML',
            '/\.outerHTML\s*=/'           => 'outerHTML assignment',
            '/document\.write\s*\(/'      => 'document.write',
            '/document\.writeln\s*\(/'    => 'document.writeln',
            '/new\s+Function\s*\(/'       => 'new Function()',
            '/\.setAttribute\s*\(\s*[\'"]on[a-z]/i' => 'setAttribute with event handler',
        ];

        $violations = [];

        foreach ($this->frontendFiles() as $path) {
            $rel   = 'public/' . basename($path);
            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $line) {
                foreach ($patterns as $pattern => $label) {
                    if (preg_match($pattern, $line)) {
                        $violations[] = "[$label] $rel:" . ($i + 1) . ': ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Dangerous HTML sink(s) found:\n" . implode("\n", $violations)
        );
    }

    public function testNoEvalOrStringTimeouts(): void
    {
        $patterns = [
            '/\beval\s*\(/'                       => 'eval()',
            '/setTimeout\s*\(\s*[\'"`]/'          => 'setTimeout with string arg',
            '/setInterval\s*\(\s*[\'"`]/'         => 'setInterval with string arg',
        ];

        $violations = [];

        foreach ($this->frontendFiles() as $path) {
            $rel   = 'public/' . basename($path);
            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $line) {
                foreach ($patterns as $pattern => $label) {
                    if (preg_match($pattern, $line)) {
                        $violations[] = "[$label] $rel:" . ($i + 1) . ': ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "eval/string-timeout patterns found:\n" . implode("\n", $violations)
        );
    }
}
