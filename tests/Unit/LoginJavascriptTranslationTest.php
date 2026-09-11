<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Render the actual login error helper with translations that need JS escaping.
 * The Node harness substitutes PHP, so this check belongs in the PHP suite.
 */
class LoginJavascriptTranslationTest extends TestCase
{
    /**
     * Translated punctuation and line breaks must remain valid string literals.
     */
    public function testQuotesBackslashesAndLineBreaksRoundTrip(): void
    {
        $this->assertRenderedMessages([
            'server_answer_error' => "L'erreur du serveur : \"réponse\" \\ chemin\nNouvelle ligne",
            'caution' => "Attention à l'apostrophe\r\n中文",
        ]);
    }

    /**
     * A translation must not be able to close the surrounding script element.
     */
    public function testHtmlCharactersCannotTerminateTheScript(): void
    {
        $this->assertRenderedMessages([
            'server_answer_error' => '</script><p title="message">&</p>',
            'caution' => "<SCRIPT>Attention</SCRIPT> & '\"",
        ]);
    }

    /**
     * Execute only the repository-owned helper template, without a live session.
     *
     * @param array<string, string> $messages
     */
    private function assertRenderedMessages(array $messages): void
    {
        $template = file_get_contents(__DIR__ . '/../../app/core/login.js.php');
        $this->assertIsString($template);
        $start = strpos($template, 'function showLoginRequestError()');
        $this->assertNotFalse($start);
        $end = strpos($template, 'function hideForgotLocalPasswordLink()', $start);
        $this->assertNotFalse($end);

        $lang = new class($messages) {
            /** @param array<string, string> $messages */
            public function __construct(private array $messages)
            {
            }

            /** Return the test translation unchanged, as Language::get() does. */
            public function get(string $key): string
            {
                return $this->messages[$key];
            }
        };

        $fixture = tempnam(sys_get_temp_dir(), 'tp-login-translation-');
        $this->assertNotFalse($fixture);
        $bufferLevel = ob_get_level();
        try {
            $this->assertNotFalse(file_put_contents($fixture, substr($template, $start, $end - $start)));
            ob_start();
            require $fixture;
            $rendered = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            unlink($fixture);
        }

        $call = 'toastr.error(';
        $callStart = strpos($rendered, $call);
        $this->assertNotFalse($callStart);
        $argsStart = $callStart + strlen($call);
        $optionsStart = strrpos($rendered, ', {');
        $this->assertNotFalse($optionsStart);
        $this->assertGreaterThan($argsStart, $optionsStart);
        $arguments = substr($rendered, $argsStart, $optionsStart - $argsStart);
        $this->assertSame(
            array_values($messages),
            json_decode('[' . $arguments . ']', true, 512, JSON_THROW_ON_ERROR)
        );
        $this->assertStringNotContainsString('</script', strtolower($rendered));
    }
}
