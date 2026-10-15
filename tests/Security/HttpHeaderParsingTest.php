<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;

final class HttpHeaderParsingTest extends TestCase
{
    public function testCacheControlWhitespaceDoesNotDiscardRequestDirectives(): void
    {
        foreach ([' ', "\t"] as $whitespace) {
            $request = Request::create('/', 'GET', [], [], [], [
                'HTTP_CACHE_CONTROL' => 'max-age=0' . str_repeat($whitespace, 25) . ', private',
            ]);

            $this->assertSame('0', $request->headers->getCacheControlDirective('max-age'));
            $this->assertTrue($request->headers->getCacheControlDirective('private'));
        }
    }

    public function testRepeatedWhitespaceBeforeSeparatorsKeepsEveryHeaderEntry(): void
    {
        $header = str_repeat('delivery' . str_repeat(' ', 25) . ',', 200);

        $this->assertCount(200, HeaderUtils::split($header, ',='));
    }

    public function testWhitespaceSeparatorsAndQuotedValuesRemainSupported(): void
    {
        $this->assertSame(['foo', 'bar'], HeaderUtils::split('foo bar', ' '));
        $this->assertSame(
            [['attachment'], ['filename', 'client delivery.pdf']],
            HeaderUtils::split('attachment; filename="client delivery.pdf"', ';=')
        );
    }
}
