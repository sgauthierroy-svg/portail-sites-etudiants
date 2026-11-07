<?php

declare(strict_types=1);

namespace Portail\Tests;

use PHPUnit\Framework\TestCase;
use Portail\Web\EnTetes;
use Portail\Web\Html;

final class WebTest extends TestCase
{
    public function testLEchappementNeutraliseLeCodeInjecte(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&apos;x&apos;)&lt;/script&gt; &amp; &quot;',
            Html::e('<script>alert(\'x\')</script> & "')
        );
    }

    public function testLEchappementRemplaceUnTexteMalEncodeAuLieuDeLeLaisserPasser(): void
    {
        self::assertStringNotContainsString('<', Html::e("\xC3<b>"));
    }

    public function testLaPolitiqueDeContenuInterditScriptsExterieursEtCadres(): void
    {
        $csp = EnTetes::securite()['Content-Security-Policy'];
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertStringNotContainsString('*', $csp);
    }

    public function testAucunePageNEstGardeeEnCache(): void
    {
        self::assertStringContainsString('no-store', EnTetes::securite()['Cache-Control']);
        self::assertSame('nosniff', EnTetes::securite()['X-Content-Type-Options']);
    }
}
