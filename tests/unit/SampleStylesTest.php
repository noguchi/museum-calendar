<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SampleStylesTest extends TestCase
{
    private const PLUGIN = __DIR__ . '/../../plugins/museum-calendar';

    public function testDefaultSampleDeclaresTheFallbacksOfThePluginStyles(): void
    {
        // 代替値そのものが var() の場合は固有の値がないので、このパターンでは拾わない。
        preg_match_all('/var\((--museum-calendar-[a-z-]+), ([^()]+?)\)/', self::read('/assets/calendar.css'), $used, PREG_SET_ORDER);
        $fallbacks = [];
        foreach ($used as [, $name, $value]) {
            self::assertSame($fallbacks[$name] ?? $value, $value, $name . ' の代替値は calendar.css で1つだけ');
            $fallbacks[$name] = $value;
        }
        preg_match_all('/^\s*(--museum-calendar-[a-z-]+): ([^;]+);/m', self::read('/samples/default.css'), $declared, PREG_SET_ORDER);
        $sample = array_column($declared, 2, 1);
        ksort($fallbacks);
        ksort($sample);

        self::assertNotSame([], $fallbacks);
        self::assertSame($fallbacks, $sample);
    }

    #[DataProvider('themedSamples')]
    public function testThemedSampleWinsOverThePluginStylesInAnyOrder(string $file): void
    {
        $css = preg_replace('~/\*.*?\*/~s', '', self::read('/samples/' . $file));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        self::assertNotSame([], $rules);
        foreach ($rules as [, $selectors, $body]) {
            preg_match_all('/^\s*([a-z-]+):/m', $body, $properties);
            if (array_filter($properties[1], static fn (string $property): bool => !str_starts_with($property, '--')) === []) {
                continue;
            }
            foreach (explode(',', $selectors) as $selector) {
                self::assertStringStartsWith(':root ', trim($selector), $file . ' は :root の外でプロパティを設定しない');
            }
        }
    }

    public static function themedSamples(): array
    {
        return [['monochrome.css'], ['pop.css']];
    }

    private static function read(string $path): string
    {
        return (string) file_get_contents(self::PLUGIN . $path);
    }
}
