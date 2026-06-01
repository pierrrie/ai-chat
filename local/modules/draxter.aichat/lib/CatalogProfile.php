<?php

namespace Draxter\Aichat;

/**
 * Профиль ассортимента магазина: не подмешивать в промпт мото/снег, если в каталоге только измельчители.
 */
class CatalogProfile
{
    private static ?bool $gardenOnly = null;

    public static function isGardenOnly(): bool
    {
        if (self::$gardenOnly !== null) {
            return self::$gardenOnly;
        }

        $profile = self::profileName();
        if (in_array($profile, ['garden', 'wood', 'chippers'], true)) {
            if (self::catalogHasSnowProducts() || self::catalogHasMotorcycleProducts()) {
                return self::$gardenOnly = false;
            }

            return self::$gardenOnly = true;
        }
        if (in_array($profile, ['full', 'multi', 'all'], true)) {
            return self::$gardenOnly = false;
        }

        return self::$gardenOnly = self::detectGardenOnlyFromCatalog();
    }

    public static function profileName(): string
    {
        $profile = strtolower(trim(Settings::get('CATALOG_PROFILE', 'auto')));
        if ($profile === '') {
            $profile = strtolower(trim((string)SiteConfig::get('catalog.profile', 'auto')));
        }

        return $profile !== '' ? $profile : 'auto';
    }

    /** Профиль «Полный» — в промпт всегда весь каталог, без сужения по ключевым словам. */
    public static function isFullCatalog(): bool
    {
        return in_array(self::profileName(), ['full', 'multi', 'all'], true);
    }

    public static function specializationLine(): string
    {
        $line = trim(Settings::get('SHOP_SPECIALIZATION', ''));
        if ($line === '') {
            $line = trim((string)SiteConfig::get('shop.specialization', ''));
        }
        if ($line !== '') {
            return $line;
        }
        if (self::isGardenOnly()) {
            return 'Draxter специализируется на измельчителях древесины, щепорезах и промышленных утилизаторах';
        }

        return Config::brand() . ' — интернет-магазин оборудования';
    }

    /**
     * Запросы про технику, которой нет в «садовом» каталоге (снегокаты, мото, квадро и т.д.).
     */
    public static function isOffCatalogVehicleQuery(string $text): bool
    {
        $q = ModelSeries::normalizeModelQuery($text);

        if (preg_match('/мотоснегокат|снегокат|снегоход|мотобукс|мотособак/ui', $q)) {
            return !self::catalogHasSnowProducts();
        }
        if (preg_match('/мотоцикл|мотовездеход|питбайк|пит\s*байк/ui', $q)) {
            return !self::catalogHasMotorcycleProducts();
        }

        return (bool)preg_match(
            '/квадроцикл|минитрактор|мотоблок|снегоубор|арктик/ui',
            $q
        );
    }

    public static function catalogHasSnowProducts(): bool
    {
        foreach (Catalog::getAllProducts() as $p) {
            $hay = mb_strtolower($p->name . ' ' . $p->category);
            if (preg_match('/мотоснегокат|снегокат|снегоход|мотобукс/ui', $hay)) {
                return true;
            }
        }

        return false;
    }

    public static function catalogHasMotorcycleProducts(): bool
    {
        foreach (Catalog::getAllProducts() as $p) {
            if (Search::isRealMotorcycleProduct($p)) {
                return true;
            }
        }

        return false;
    }

    public static function isOffCatalogQuery(string $text): bool
    {
        if ($text === '' || !self::isGardenOnly()) {
            return false;
        }

        return self::isOffCatalogVehicleQuery($text)
            || (bool)preg_match('/\b(лайт|light|про|pro)\b/ui', $text)
                && preg_match('/снег|мото|зим/ui', $text);
    }

    /**
     * @return string[]
     */
    public static function defaultDomainRules(): array
    {
        if (!self::isGardenOnly()) {
            return [];
        }

        $spec = self::specializationLine();

        return [
            'В каталоге только измельчители древесины, щепорезы, утилизаторы и сопутствующее оборудование — без снегокатов, снегоходов, мотоциклов и мотовездеходов.',
            $spec . '.',
            'Если клиент спрашивает товар вне ассортимента — честно откажи и предложи 1–2 подходящие категории из каталога ниже. Не выдумывай другую специализацию магазина.',
            'ЗАПРЕЩЕНО писать, что Draxter продаёт или «специализируется на» мотоциклах, мотовездеходах, снегокатах или зимней технике.',
            'Слова «лайт» и «про» в чате — не названия товаров Draxter; сравнивай модели из каталога по мощности и назначению, если клиент хочет сравнение.',
        ];
    }

    public static function offCatalogPromptBlock(string $lastUser): string
    {
        $spec = self::specializationLine();

        return "Сейчас клиент спрашивает: «{$lastUser}» — это ВНЕ ассортимента.\n"
            . "Ответь кратко (2–4 предложения): таких товаров нет; {$spec}.\n"
            . 'Предложи 1–2 реальные категории из каталога (щепорез, измельчитель, утилизатор). '
            . 'Не упоминай мотоциклы, мотовездеходы, снегокаты и зимнюю технику как нашу специализацию.';
    }

    public static function scopeEnabled(string $scope): bool
    {
        if (!self::isGardenOnly()) {
            return true;
        }

        return in_array($scope, ['garden', 'overview'], true);
    }

    public static function resetCache(): void
    {
        self::$gardenOnly = null;
    }

    private static function detectGardenOnlyFromCatalog(): bool
    {
        $all = Catalog::getAllProducts();
        if ($all === []) {
            return false;
        }

        $garden = 0;
        $vehicle = 0;
        foreach ($all as $p) {
            $hay = mb_strtolower($p->name . ' ' . $p->category . ' ' . $p->description);
            if (preg_match('/измельчит|щепорез|утилизатор|древесин|мульч|веткорез/ui', $hay)) {
                $garden++;
            }
            if (preg_match('/снегокат|снегоход|мотоснегокат|мотобукс|мотоцикл|мотовездеход|питбайк/ui', $hay)) {
                $vehicle++;
            }
        }

        return $garden > 0 && $vehicle === 0;
    }
}
