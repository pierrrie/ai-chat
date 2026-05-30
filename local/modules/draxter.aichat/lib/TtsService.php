<?php



namespace Draxter\Aichat;



class TtsService

{

    /**

     * @return array{mode: string, text?: string}

     */

    public static function synthesize(string $text): array

    {

        return ['mode' => 'browser', 'text' => self::preparePlainText($text)];

    }



    public static function isConfigured(): bool

    {

        return true;

    }



    public static function preparePlainText(string $text): string
    {
        $max = Settings::voiceTtsMaxChars();
        $plain = preg_replace('/<!--.*?-->/s', '', $text) ?? $text;
        $plain = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $plain) ?? $plain;
        $plain = preg_replace('/\*\*([^*]+)\*\*/', '$1', $plain) ?? $plain;
        $plain = preg_replace('/\*([^*\n]+)\*/', '$1', $plain) ?? $plain;
        $plain = preg_replace('/`([^`]+)`/', '$1', $plain) ?? $plain;
        $plain = preg_replace('/^#{1,6}\s+/m', '', $plain) ?? $plain;
        $plain = preg_replace('/^[\-*•]\s+/m', '', $plain) ?? $plain;
        $plain = self::normalizeTextForTts($plain);
        $plain = preg_replace('/(?<!\w)\*(?!\w)/u', '', $plain) ?? $plain;
        $plain = strip_tags($plain);
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
        $plain = trim($plain);
        if (mb_strlen($plain) > $max) {
            $plain = rtrim(mb_substr($plain, 0, $max)) . '…';
        }

        return $plain;
    }

    /** Keep in sync with plainTextForTts() in aichat.chat script.js */
    private static function normalizeTextForTts(string $text): string
    {
        $s = $text;
        $s = preg_replace_callback(
            '/(\d[\d\s\x{00a0}]*)\s*(?:₽|руб\.?|RUB|rub)(?:[\s.,;]|$)/iu',
            static function (array $m): string {
                $num = (int)preg_replace('/[\s\x{00a0}]+/u', '', $m[1]);
                return self::formatRussianNumberSpeakable($num) . ' рублей ';
            },
            $s
        ) ?? $s;
        $s = preg_replace_callback(
            '/\d{1,3}(?:[\s\x{00a0}]\d{3})+(?:[.,]\d+)?/u',
            static function (array $m): string {
                return preg_replace('/[\s\x{00a0}]+/u', '', $m[0]) ?? $m[0];
            },
            $s
        ) ?? $s;
        $s = preg_replace('/(\d)\s*[\*×xX]\s*(\d)/u', '$1 на $2', $s) ?? $s;
        $s = preg_replace('/[×]/u', ' на ', $s) ?? $s;
        $s = preg_replace('/[–—−]/u', '-', $s) ?? $s;
        $s = preg_replace('/^\s*\|.*\|\s*$/m', ' ', $s) ?? $s;
        $s = preg_replace('/\|/u', ' ', $s) ?? $s;
        $s = preg_replace('/^>\s+/m', '', $s) ?? $s;
        $s = preg_replace('/\*+/u', '', $s) ?? $s;

        return $s;
    }

    private static function pluralRu(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return $few;
        }

        return $many;
    }

    private static function formatRussianNumberSpeakable(int $n): string
    {
        if ($n < 0) {
            return (string)$n;
        }
        if ($n >= 1000000) {
            $millions = intdiv($n, 1000000);
            $s = $millions . ' ' . self::pluralRu($millions, 'миллион', 'миллиона', 'миллионов');
            $n %= 1000000;
            if ($n >= 1000) {
                $thousands = intdiv($n, 1000);
                $s .= ' ' . $thousands . ' ' . self::pluralRu($thousands, 'тысяча', 'тысячи', 'тысяч');
                $n %= 1000;
            }
            if ($n > 0) {
                $s .= ' ' . $n;
            }

            return $s;
        }
        if ($n >= 1000) {
            $thousands = intdiv($n, 1000);
            $s = $thousands . ' ' . self::pluralRu($thousands, 'тысяча', 'тысячи', 'тысяч');
            $n %= 1000;
            if ($n > 0) {
                $s .= ' ' . $n;
            }

            return $s;
        }

        return (string)$n;
    }

}

