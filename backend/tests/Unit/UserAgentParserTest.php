<?php

namespace Tests\Unit;

use App\Support\UserAgentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserAgentParserTest extends TestCase
{
    private UserAgentParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new UserAgentParser;
    }

    public static function browserProvider(): array
    {
        return [
            'Chrome / Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'desktop', 'Chrome 120', 'Windows 10/11',
            ],
            'Edge / Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.2210.61',
                'desktop', 'Edge 120', 'Windows 10/11',
            ],
            'Safari / macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
                'desktop', 'Safari 17', 'macOS',
            ],
            'Firefox / Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
                'desktop', 'Firefox 121', 'Linux',
            ],
            'Chrome / Android' => [
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
                'mobile', 'Chrome 120', 'Android 14',
            ],
            'Safari / iOS' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
                'mobile', 'Safari 17', 'iOS 17',
            ],
            'Opera / Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36 OPR/105.0.0.0',
                'desktop', 'Opera 105', 'Windows 10/11',
            ],
            'curl' => [
                'curl/8.4.0',
                'bot', 'unknown', 'unknown',
            ],
        ];
    }

    #[DataProvider('browserProvider')]
    public function test_it_parses_known_user_agents(string $ua, string $device, string $browser, string $platform): void
    {
        $result = $this->parser->parse($ua);

        $this->assertSame($device, $result['device'], 'device mismatch for: '.$ua);
        $this->assertSame($browser, $result['browser'], 'browser mismatch for: '.$ua);
        $this->assertSame($platform, $result['platform'], 'platform mismatch for: '.$ua);
    }

    public function test_empty_user_agent_returns_all_unknown(): void
    {
        $result = $this->parser->parse('');

        $this->assertSame([
            'device' => 'unknown',
            'browser' => 'unknown',
            'platform' => 'unknown',
        ], $result);
    }

    public function test_null_user_agent_returns_all_unknown_without_throwing(): void
    {
        $result = $this->parser->parse(null);

        $this->assertSame('unknown', $result['device']);
        $this->assertSame('unknown', $result['browser']);
        $this->assertSame('unknown', $result['platform']);
    }

    public static function botProvider(): array
    {
        return [
            'bot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'crawler' => ['SomeCrawler/1.0'],
            'spider' => ['SomeSpider/2.0'],
            'wget' => ['Wget/1.21.3'],
            'python-requests' => ['python-requests/2.31.0'],
            'postman' => ['PostmanRuntime/7.32.3'],
        ];
    }

    #[DataProvider('botProvider')]
    public function test_it_classifies_common_bots_and_tools(string $ua): void
    {
        $result = $this->parser->parse($ua);

        $this->assertSame('bot', $result['device']);
    }

    public function test_edge_is_never_misclassified_as_chrome_or_safari(): void
    {
        $edgeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.2210.61';

        $result = $this->parser->parse($edgeUa);

        $this->assertStringStartsWith('Edge', $result['browser']);
    }

    public function test_chrome_is_never_misclassified_as_safari(): void
    {
        $chromeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $result = $this->parser->parse($chromeUa);

        $this->assertStringStartsWith('Chrome', $result['browser']);
    }
}
