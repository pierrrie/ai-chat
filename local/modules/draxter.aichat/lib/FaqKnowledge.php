<?php



namespace Draxter\Aichat;



/**

 * Справочник ответов вне каталога (доставка, оплата, дилеры, техника).

 * Формат в админке (каждый блок):

 *   # ключевые слова: доставка, город, срок

 *   # заголовок: Доставка (необязательно)

 *   Текст ответа для нейросети…

 */

class FaqKnowledge

{

    private const MAX_SECTIONS = 16;

    private const MAX_CHARS = 12000;



    /**

     * @param array<int, array{role: string, content: string}> $messages

     */

    public static function buildPromptSection(

        string $lastUser,

        array $messages,

        ?string $rawOverride = null,

        ?string $introOverride = null

    ): string {

        $intro = trim($introOverride ?? Settings::getString('prompts.faq_intro', ''));

        $raw = trim($rawOverride ?? Settings::faqKnowledgeRaw());

        if ($intro === '' && $raw === '') {

            return '';

        }



        $sections = self::parseSections($raw);

        $haystack = self::userTextHaystack($lastUser, $messages);

        $selected = self::selectSections($sections, $haystack);



        $parts = [];

        if ($intro !== '') {

            $parts[] = $intro;

        }

        $parts[] = '--- Справочник компании (вопросы не из каталога товаров) ---';

        if ($selected === []) {

            $parts[] = 'В справочнике нет готового ответа — кратко предложи связаться с менеджером {brand} (телефон в чате).';

        } else {

            foreach ($selected as $section) {

                if ($section['title'] !== '') {

                    $parts[] = '### ' . $section['title'];

                }

                $parts[] = $section['body'];

            }

        }

        $parts[] = '--- Конец справочника ---';



        $block = Settings::format(implode("\n", $parts), ['brand' => Config::brand()]);



        return mb_strlen($block) > self::MAX_CHARS

            ? mb_substr($block, 0, self::MAX_CHARS) . "\n…"

            : $block;

    }



    /**

     * @return array{

     *   question: string,

     *   matched: array<int, array{title: string, keywords: string[], body: string|null}>,

     *   draftAnswer: string,

     *   aiAnswer: string|null,

     *   aiError: string|null,

     *   promptSection: string

     * }

     */

    public static function adminPreview(

        string $question,

        bool $withAi = false,

        ?string $rawOverride = null,

        ?string $introOverride = null,

        ?string $customBlocksOverride = null

    ): array {

        $messages = [['role' => 'user', 'content' => $question]];

        $raw = trim($rawOverride ?? Settings::faqKnowledgeRaw());

        $sections = self::parseSections($raw);

        $haystack = self::userTextHaystack($question, $messages);

        $selected = self::selectSections($sections, $haystack);



        $matched = [];

        foreach ($selected as $section) {

            $body = trim($section['body']);

            $matched[] = [

                'title' => $section['title'],

                'keywords' => $section['keywords'],

                'body' => $body !== '' ? $body : null,

            ];

        }



        $shopName = Config::shopName();

        $customRaw = $customBlocksOverride !== null ? trim($customBlocksOverride) : null;

        $matchedCustom = CustomPrompts::matchedPhraseBlocks($question, $customRaw);

        $phraseOverride = CustomPrompts::buildPhraseOverrideSection($shopName, false, $question, $customRaw);

        $hasPhraseMatch = $matchedCustom !== [];



        $promptSection = self::buildPromptSection($question, $messages, $rawOverride, $introOverride);

        if ($phraseOverride !== '') {

            $promptSection = trim($promptSection . "\n\n" . $phraseOverride);

        }



        if ($hasPhraseMatch) {

            $draftAnswer = implode("\n\n", array_map(

                static fn(array $block): string => trim($block['body']),

                $matchedCustom

            ));

        } else {

            $draftAnswer = self::buildDraftAnswer($selected, $question);

        }

        $aiAnswer = null;

        $aiError = null;



        if ($withAi) {

            try {

                if (!Config::hasLlmApiKey()) {

                    throw new \RuntimeException('API-ключ не задан — укажите ключ на вкладке «AI»');

                }

                $aiAnswer = self::generateAiAnswer($question, $messages, $promptSection, $hasPhraseMatch);

            } catch (\Throwable $e) {

                $formatted = LlmClient::formatError($e, true);

                $aiError = (string)($formatted['error'] ?? $e->getMessage());

            }

        }



        return [

            'question' => $question,

            'matched' => $matched,

            'matchedCustom' => $matchedCustom,

            'draftAnswer' => $draftAnswer,

            'aiAnswer' => $aiAnswer,

            'aiError' => $aiError,

            'promptSection' => $promptSection,

        ];

    }



    /**

     * @param array<int, array{keywords: string[], title: string, body: string}> $selected

     */

    private static function buildDraftAnswer(array $selected, string $question): string

    {

        if ($selected === []) {

            return 'Подходящий блок не найден. Бот предложит связаться с менеджером.';

        }



        $parts = [];

        foreach ($selected as $section) {

            $title = $section['title'] !== '' ? $section['title'] : 'ответ';

            $body = trim($section['body']);

            if ($body === '' || self::isPlaceholderBody($body)) {

                $parts[] = "Блок «{$title}» подобран, но текст ещё не заполнен — нейросеть не сможет дать конкретику, только предложит менеджера.";

                continue;

            }

            $parts[] = $body;

        }



        return implode("\n\n", $parts);

    }



    /**

     * @param array<int, array{role: string, content: string}> $messages

     */

    private static function generateAiAnswer(
        string $question,
        array $messages,
        string $faqPromptSection,
        bool $hasPhraseMatch = false
    ): string

    {

        $shopName = Config::shopName();

        $roleLine = Settings::format(

            Settings::getString('prompts.role_line', 'Ты — AI-консультант «{shop_name}» ({brand}). Отвечай живым языком, как консультант в чате.'),

            ['shop_name' => $shopName, 'brand' => Config::brand()]

        );



        $taskLine = $hasPhraseMatch
            ? 'Клиент задал вопрос по теме из блока «Свои промпты» (в конце инструкции). Ответь кратко (1–3 предложения) по этому блоку — он важнее справочника FAQ. Не пиши, что информации нет, если она указана в блоке.'
            : 'Клиент задал вопрос не про товар из каталога. Ответь кратко (2–4 предложения), только по справочнику ниже. '
            . 'Не выдумывай цены, сроки и города. Если в справочнике нет данных — предложи менеджера и телефон в чате.';

        $system = implode("\n", array_filter([

            $roleLine,

            $taskLine,

            $faqPromptSection,

        ]));



        try {

            if (Config::aiProvider() === 'gemini') {

                return GeminiChat::complete(

                    Config::get('GEMINI_API_KEY'),

                    Config::get('GEMINI_MODEL', 'gemini-2.5-flash'),

                    $system,

                    $messages,

                    400

                );

            }



            $llm = LlmClient::create();

            return LlmClient::complete($llm, $system, $messages, 400);

        } catch (\Throwable $e) {

            $fallback = Config::aiProvider() === 'gemini' && LlmClient::isQuotaOrRateLimitError($e)

                ? LlmClient::createFallbackLlm()

                : null;

            if ($fallback !== null) {

                return LlmClient::complete($fallback, $system, $messages, 400);

            }

            throw $e;

        }

    }



    /**

     * @return array<int, array{keywords: string[], title: string, body: string}>

     */

    public static function parseSections(string $raw): array

    {

        $raw = trim(str_replace(["\r\n", "\r"], "\n", $raw));

        if ($raw === '') {

            return [];

        }



        if (!preg_match('/^#\s*(keywords|ключевые\s*слова?|ключевые)/umi', $raw)) {

            return [[

                'keywords' => [],

                'title' => '',

                'body' => self::normalizeBody($raw),

            ]];

        }



        $lines = explode("\n", $raw);

        $sections = [];

        $current = null;



        $flush = static function () use (&$sections, &$current): void {

            if ($current === null) {

                return;

            }

            $body = self::normalizeBody($current['body']);

            if ($body !== '' || $current['title'] !== '' || $current['keywords'] !== []) {

                $sections[] = [

                    'keywords' => $current['keywords'],

                    'title' => $current['title'],

                    'body' => $body,

                ];

            }

            $current = null;

        };



        foreach ($lines as $line) {

            $trimmed = trim($line);

            if ($trimmed === '' || $trimmed === '---') {

                $flush();

                continue;

            }



            if (preg_match('/^#\s*(keywords|ключевые\s*слова?|ключевые)\s*:\s*(.+)$/ui', $trimmed, $m)) {

                $flush();

                $current = [

                    'keywords' => self::splitKeywords($m[2]),

                    'title' => '',

                    'body' => '',

                ];

                continue;

            }



            if (preg_match('/^#\s*(title|заголовок)\s*:\s*(.+)$/ui', $trimmed, $m)) {

                if ($current === null) {

                    $current = ['keywords' => [], 'title' => '', 'body' => ''];

                }

                $current['title'] = trim($m[2]);

                continue;

            }



            if (preg_match('/^#\s*(.+)$/u', $trimmed, $m) && $current !== null) {

                if (str_contains($m[1], ',')) {

                    $current['keywords'] = array_merge($current['keywords'], self::splitKeywords($m[1]));

                } else {

                    $current['keywords'][] = mb_strtolower(trim($m[1]));

                }

                continue;

            }



            if (preg_match('/^##\s+(.+)$/u', $trimmed, $m)) {

                $flush();

                $current = ['keywords' => [], 'title' => trim($m[1]), 'body' => ''];

                continue;

            }



            if ($current === null) {

                $current = ['keywords' => [], 'title' => '', 'body' => ''];

            }

            $current['body'] .= ($current['body'] === '' ? '' : "\n") . $line;

        }



        $flush();



        return array_slice($sections, 0, self::MAX_SECTIONS);

    }



    /**

     * @param array<int, array{keywords: string[], title: string, body: string}> $sections

     * @return array<int, array{keywords: string[], title: string, body: string}>

     */

    private static function selectSections(array $sections, string $haystack): array

    {

        if ($sections === []) {

            return [];

        }



        $matched = [];

        foreach ($sections as $section) {

            if ($section['keywords'] === []) {

                $matched[] = $section;

                continue;

            }

            foreach ($section['keywords'] as $kw) {

                if ($kw !== '' && mb_strpos($haystack, $kw) !== false) {

                    $matched[] = $section;

                    break;

                }

            }

        }



        if ($matched !== []) {

            return $matched;

        }



        return $sections;

    }



    /**

     * @param array<int, array{role: string, content: string}> $messages

     */

    private static function userTextHaystack(string $lastUser, array $messages): string

    {

        $chunks = [mb_strtolower($lastUser)];

        $userCount = 0;

        for ($i = count($messages) - 1; $i >= 0 && $userCount < 4; $i--) {

            if (($messages[$i]['role'] ?? '') !== 'user') {

                continue;

            }

            $chunks[] = mb_strtolower(trim((string)($messages[$i]['content'] ?? '')));

            $userCount++;

        }



        return implode(' ', $chunks);

    }



    private static function normalizeBody(string $body): string

    {

        $body = trim($body);

        if ($body === '') {

            return '';

        }

        $stripped = preg_replace('/^\s*ЗАПОЛНИТЕ\s*:\s*/ui', '', $body);

        return trim($stripped ?? $body);

    }



    private static function isPlaceholderBody(string $body): bool

    {

        $body = trim($body);

        if ($body === '') {

            return true;

        }

        if (preg_match('/^\s*ЗАПОЛНИТЕ\s*:/ui', $body)) {

            return true;

        }

        return false;

    }



    /**

     * @return string[]

     */

    private static function splitKeywords(string $line): array

    {

        $parts = preg_split('/[,;|]+/u', $line) ?: [];



        return array_values(array_filter(array_map(

            static fn($w) => mb_strtolower(trim($w)),

            $parts

        ), static fn($w) => $w !== '' && mb_strlen($w) >= 2));

    }

}


