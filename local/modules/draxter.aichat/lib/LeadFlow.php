<?php

namespace Draxter\Aichat;

class LeadFlow
{
    public static function leadPhoneCta(): string
    {
        $msg = Settings::message('lead_phone_cta');
        return $msg !== '' ? $msg : "\n\nЕсли удобно — оставьте номер телефона: менеджер " . Config::brand() . ' перезвонит и поможет с выбором и оформлением.';
    }

    public static function leadPhoneCtaSuffix(string $assistantSoFar): string
    {
        $cta = trim(self::leadPhoneCta());
        if ($cta === '') {
            return '';
        }

        $assistantSoFar = rtrim($assistantSoFar);
        if ($assistantSoFar === '') {
            return $cta;
        }

        if (str_ends_with($assistantSoFar, "\n\n")) {
            return $cta;
        }
        if (str_ends_with($assistantSoFar, "\n")) {
            return "\n" . $cta;
        }

        return "\n\n" . $cta;
    }

    public static function isSmallTalkUserMessage(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        $pattern = Settings::regex(
            'small_talk_regex',
            '^(ты\s+кто|кто\s+ты|что\s+(?:ты\s+)?умеешь|чем\s+(?:ты\s+)?можешь|что\s+ты\s+делаешь|как\s+ты\s+работаешь|как\s+тебя\s+зовут|как\s+зовут|привет|здравствуйте|добрый\s+день|help|помощь)[!.?\s]*$'
        );

        return (bool)preg_match('/' . $pattern . '/u', $t);
    }

    public static function hasProductShoppingIntent(string $text): bool
    {
        $t = trim($text);
        if ($t === '' || self::isSmallTalkUserMessage($t)) {
            return false;
        }
        $productRx = Settings::regex('product_keywords_regex', '');
        if ($productRx !== '' && preg_match('/' . $productRx . '/ui', $t)) {
            return true;
        }
        if (preg_match('/\d[\d\s]{3,}\s*(?:руб|₽|р\.?)/ui', $t)) {
            return true;
        }
        $shopRx = Settings::regex('shopping_intent_regex', 'подобр|купить|цена|наличи|характеристик|сравни|какой\s+лучше|модел|нужен|нужна|нужно|хочу');

        return (bool)preg_match('/' . $shopRx . '/ui', $t);
    }

    public static function isPurchaseIntent(string $text): bool
    {
        $rx = Settings::regex('purchase_intent_regex', 'хочу\s+купить|готов\s+купить|оформ(ить|ляю)|беру\b|заказать|куплю\b');

        return (bool)preg_match('/' . $rx . '/ui', $text);
    }

    public static function isPhoneOnlyUserMessage(string $text): bool
    {
        $phone = Phone::extract($text);
        if ($phone === null) {
            return false;
        }
        $rest = preg_replace('/[\d\s\-+().]/u', ' ', $text);
        $rest = preg_replace('/(?:тел|телефон|номер|мой|моя)/ui', ' ', $rest ?? '');
        $rest = trim(preg_replace('/\s+/u', ' ', $rest ?? '') ?? '');
        return mb_strlen($rest) <= 2;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public static function shouldAppendLeadCta(string $sessionId, array $messages, string $assistantText = ''): bool
    {
        $state = self::analyze($sessionId, $messages);
        if ($state['leadCreated'] || $state['hasPhone']) {
            return false;
        }

        $lastUser = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUser = $messages[$i]['content'] ?? '';
                break;
            }
        }
        if ($lastUser === '' || self::isSmallTalkUserMessage($lastUser)) {
            return false;
        }
        if (self::assistantAlreadyAsksForPhone($assistantText)) {
            return false;
        }
        if (self::isPurchaseIntent($lastUser)) {
            return true;
        }

        return $state['userTurns'] >= 1;
    }

    public static function assistantAlreadyAsksForPhone(string $text): bool
    {
        $lower = mb_strtolower(trim($text));
        if ($lower === '') {
            return false;
        }

        if (preg_match('/оставь\w*\s+(?:ваш\s+|свой\s+)?номер\s+телефона/ui', $lower)) {
            return true;
        }
        if (preg_match('/(?:оставьте|оставь|укажите|напишите|пришлите|сообщите).{0,50}(?:номер\s+)?телефон/ui', $lower)) {
            return true;
        }
        if (preg_match('/(?:номер\s+телефона|ваш\s+телефон).{0,40}(?:менеджер|перезвон|свяж)/ui', $lower)) {
            return true;
        }
        if (preg_match('/менеджер\s+.{0,40}(?:свяжется|перезвонит|позвонит)/ui', $lower)) {
            return true;
        }

        $cta = trim(self::leadPhoneCta());
        if ($cta !== '') {
            $ctaNorm = mb_strtolower(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $cta) ?? '') ?? '');
            $textNorm = mb_strtolower(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $lower) ?? '') ?? '');
            if ($ctaNorm !== '' && mb_strlen($ctaNorm) >= 15 && str_contains($textNorm, mb_substr($ctaNorm, 0, 24))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param Product[] $products
     */
    public static function buildPromptSection(string $sessionId, array $messages, array $products = []): string
    {
        $noGreeting = 'ЗАПРЕЩЕНО начинать ответ с «Привет», «Здравствуйте», «Добрый день» и т.п. — клиент уже в диалоге. Сразу отвечай по сути.';

        $lastUser = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUser = $messages[$i]['content'] ?? '';
                break;
            }
        }
        if ($lastUser !== '' && self::isSmallTalkUserMessage($lastUser)) {
            return "Служебный вопрос о боте. Ответь кратко (1–3 предложения): кто ты и чем помогаешь. ЗАПРЕЩЕНО перечислять товары, цены, «в наличии» и ссылки.\n{$noGreeting}";
        }

        $state = self::analyze($sessionId, $messages);
        $productHint = 'Подбирай товары сам из полного каталога в системном сообщении.';

        $phoneCta = Settings::message('lead_phone_cta_prompt');
        if ($phoneCta === '') {
            $phoneCta = 'ОБЯЗАТЕЛЬНО заверши ответ отдельной последней строкой (после подбора товаров): «Если удобно — оставьте номер телефона: менеджер '
                . Config::brand() . ' перезвонит и поможет с выбором и оформлением.» Без этой строки ответ неполный.';
        }

        $noFakeLead = 'ЗАПРЕЩЕНО писать «лид создан» или «менеджер свяжется», если клиент НЕ оставил номер телефона в чате.';

        if ($state['leadCreated'] && $state['hasPhone']) {
            return "Телефон {$state['phone']} получен, лид в CRM. Поблагодари: менеджер перезвонит. Не проси номер снова.\n{$noGreeting}\n{$productHint}";
        }

        if ($state['hasPhone']) {
            return "Телефон уже есть — подтверди звонок менеджера, не проси номер повторно.\n{$noGreeting}\n{$productHint}";
        }

        $lastUser = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $lastUser = $messages[$i]['content'] ?? '';
                break;
            }
        }
        if ($lastUser !== '' && self::isPurchaseIntent($lastUser)) {
            return "Клиент хочет купить, но номера нет. Не говори что заявка уже создана. Попроси номер для оформления.\n{$phoneCta}\n{$noGreeting}\n{$productHint}";
        }

        if ($state['userTurns'] >= 1) {
            return "{$phoneCta}\n{$noFakeLead}\n{$noGreeting}\n{$productHint}";
        }

        return "Консультируй по каталогу.\n{$noGreeting}\n{$productHint}";
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param Product[] $products
     * @param array<string, string|null> $tracking
     */
    public static function tryCreateLead(string $sessionId, array $messages, array $products, array $tracking = []): ?array
    {
        $state = self::analyze($sessionId, $messages);
        if (!$state['hasPhone'] || $state['leadCreated']) {
            return null;
        }

        $phone = Phone::extractFromMessages($messages);
        if (!$phone) {
            return null;
        }

        $clientName = Phone::extractNameFromMessages($messages);
        $result = LeadDispatcher::dispatch($sessionId, $messages, $products, $phone, $clientName, $tracking);

        if ($result['ok']) {
            ChatLog::markLeadCreated($sessionId, $phone, $result['leadId'], $result['contactId']);
        }

        return $result;
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{userTurns: int, hasPhone: bool, phone: ?string, leadCreated: bool}
     */
    public static function analyze(string $sessionId, array $messages): array
    {
        $userTurns = 0;
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'user') {
                $userTurns++;
            }
        }

        $phone = Phone::extractFromMessages($messages);
        $leadMeta = ChatLog::getLeadMeta($sessionId);

        $hasPhone = $phone !== null;

        return [
            'userTurns' => $userTurns,
            'hasPhone' => $hasPhone,
            'phone' => $phone,
            'leadCreated' => !empty($leadMeta['created']) && $hasPhone,
        ];
    }
}
