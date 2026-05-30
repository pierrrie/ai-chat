<?php

namespace Draxter\Aichat;

class Prompts
{
    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function buildSystemPrompt(
        string $shopName,
        string $siteUrl,
        bool $detailed = false,
        string $sessionId = '',
        array $messages = []
    ): string {
        $lastUser = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUser = $messages[$i]['content'] ?? '';
                break;
            }
        }
        $smallTalk = $lastUser !== '' && LeadFlow::isSmallTalkUserMessage($lastUser);

        $styleBlock = $detailed
            ? Settings::getString('prompts.style_detailed', '')
            : Settings::getString('prompts.style_brief', '');
        if ($styleBlock === '') {
            $styleBlock = $detailed
                ? 'Режим: ПОДРОБНЫЙ ОТВЕТ. Структура: заголовок **модель**, характеристики из каталога, описание, для кого подойдёт, ссылка **[Название](url)** — цена · наличие.'
                : 'Режим: ПОДБОР. 4–6 предложений + 1–3 товара. Каждый товар: **[Название](ссылка из каталога)** — цена · наличие · параметры.';
        }

        $searchBlock = $smallTalk ? '' : Settings::getString('prompts.search_rules', '');
        $synonyms = Settings::getString('prompts.catalog_synonyms_line', '');
        if ($searchBlock !== '' && $synonyms !== '' && !str_contains($searchBlock, $synonyms)) {
            $searchBlock = str_replace(
                'Сам находи подходящие по запросу.',
                'Сам находи подходящие по запросу (' . $synonyms . ').',
                $searchBlock
            );
        }

        $catalogHeader = Settings::getString('prompts.catalog_header', 'Каталог:');
        $catalogSection = $smallTalk
            ? ''
            : "\n\n{$catalogHeader}\n---\n"
            . CatalogPrompt::buildForAiPrompt($siteUrl, $messages)
            . "\n---";

        $leadBlock = LeadFlow::buildPromptSection($sessionId, $messages);

        $roleLine = Settings::format(
            Settings::getString('prompts.role_line', 'Ты — AI-консультант «{shop_name}» ({brand}). Отвечай живым языком, как консультант в чате.'),
            ['shop_name' => $shopName, 'brand' => Config::brand()]
        );
        $smallTalkHint = Settings::getString('prompts.small_talk_hint', 'На «кто ты» / «что умеешь» — только 1–3 предложения о себе, БЕЗ товаров, цен и ссылок.');
        $rulesCommon = Settings::getString('prompts.rules_common', 'Правила: только данные каталога, ссылки markdown, русский язык.');
        $linkRules = $smallTalk ? '' : Settings::getString('prompts.link_rules', '');
        if ($linkRules === '' && !$smallTalk) {
            $linkRules = 'У каждого товара в каталоге есть поле URL: https://… — оформляй товар как **[Название](URL)**. '
                . 'ЗАПРЕЩЕНО писать, что ссылок нет, что цена/URL не указаны в списке, или что ссылки даст только менеджер.';
        }
        $linksRequestBlock = '';
        if (!$smallTalk && $lastUser !== '' && ResponseEnrich::userWantsProductLinks($lastUser)) {
            $linksRequestBlock = 'Клиент просит ссылки: перечисли все упомянутые модели с markdown-ссылками **[Название](URL)** из каталога (URL в конце строки товара).';
        }
        $domainRules = Settings::domainRules();
        if ($domainRules === [] && CatalogProfile::isGardenOnly()) {
            $domainRules = CatalogProfile::defaultDomainRules();
        }
        $domainBlock = $domainRules !== [] ? implode("\n", array_map(static fn($r) => '- ' . $r, $domainRules)) : '';

        $offCatalogBlock = '';
        if (!$smallTalk && $lastUser !== '' && !CustomPrompts::hasPhraseMatch($lastUser, $smallTalk)
            && CatalogProfile::isOffCatalogQuery($lastUser)) {
            $offCatalogBlock = CatalogProfile::offCatalogPromptBlock($lastUser);
        }

        $faqBlock = FaqKnowledge::buildPromptSection($lastUser, $messages);

        $parts = [
            $roleLine,
            $smallTalkHint,
        ];
        if ($searchBlock !== '') {
            $parts[] = $searchBlock;
        }
        $parts[] = $styleBlock;
        $parts[] = $rulesCommon;
        if ($linkRules !== '') {
            $parts[] = $linkRules;
        }
        if ($linksRequestBlock !== '') {
            $parts[] = $linksRequestBlock;
        }
        if ($domainBlock !== '') {
            $parts[] = $domainBlock;
        }
        $customPrompts = CustomPrompts::buildPromptSection($shopName, $smallTalk, $lastUser);
        if ($customPrompts !== '') {
            $parts[] = $customPrompts;
        }
        if ($offCatalogBlock !== '') {
            $parts[] = $offCatalogBlock;
        }
        if ($faqBlock !== '') {
            $parts[] = $faqBlock;
        }
        $phraseOverride = CustomPrompts::buildPhraseOverrideSection($shopName, $smallTalk, $lastUser);
        if ($phraseOverride !== '') {
            $parts[] = $phraseOverride;
        }
        $parts[] = "Работа с заявкой (лид в CRM):\n{$leadBlock}{$catalogSection}";

        return implode("\n", array_filter($parts, static fn($p) => trim($p) !== ''));
    }
}
