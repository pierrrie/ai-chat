<?php

namespace Draxter\Aichat;

use Bitrix\Main\Data\Cache;
use Bitrix\Main\Web\HttpClient;

class Catalog
{
    /** @var Product[]|null */
    private static ?array $products = null;

    /** @var array<string, mixed>|null */
    private static ?array $meta = null;

    private static string $loadedFrom = '';

    private static int $loadedAt = 0;

    public static function refresh(): void
    {
        self::$products = null;
        self::$meta = null;
        CatalogProfile::resetCache();
        self::getAllProducts();
    }

    /**
     * @return Product[]
     */
    public static function getAllProducts(): array
    {
        if (self::$products !== null) {
            return self::$products;
        }

        $source = Config::get('CATALOG_SOURCE', 'yml_url');
        $cacheTtl = max(60, (int)Config::get('CATALOG_REFRESH_MINUTES', '60') * 60);
        $cacheId = 'draxter_aichat_catalog_' . md5($source . Config::get('CATALOG_URL') . Config::get('CATALOG_IBLOCK_ID'));

        $cache = Cache::createInstance();
        if ($cache->initCache($cacheTtl, $cacheId, '/draxter.aichat/catalog')) {
            $vars = $cache->getVars();
            self::$products = array_map(static fn($row) => Product::fromArray($row), $vars['products'] ?? []);
            self::$meta = $vars['meta'] ?? [];
            self::$loadedFrom = (string)($vars['path'] ?? '');
            self::$loadedAt = (int)($vars['loadedAt'] ?? 0);
            return self::$products;
        }

        if ($source === 'iblock') {
            $iblockId = (int)Config::get('CATALOG_IBLOCK_ID', '0');
            self::$products = BitrixCatalog::load($iblockId);
            self::$meta = ['count' => count(self::$products), 'shopName' => Config::shopName()];
            self::$loadedFrom = 'iblock:' . $iblockId;
        } elseif ($source === 'yml_file') {
            $path = Config::get('CATALOG_PATH', '');
            if ($path === '' || !is_readable($path)) {
                throw new \RuntimeException('CATALOG_PATH не найден или недоступен');
            }
            $xml = file_get_contents($path);
            self::$products = YmlCatalog::parseXml($xml ?: '');
            self::$meta = YmlCatalog::metaFromXml($xml ?: '');
            self::$loadedFrom = $path;
        } else {
            $url = self::resolveCatalogUrl(Config::get('CATALOG_URL', ''));
            if ($url === '') {
                throw new \RuntimeException('Не задан CATALOG_URL');
            }
            $xml = self::fetchXml($url);
            self::$products = YmlCatalog::parseXml($xml);
            self::$meta = YmlCatalog::metaFromXml($xml);
            self::$loadedFrom = $url;
        }

        self::$loadedAt = time();

        if ($cache->startDataCache()) {
            $cache->endDataCache([
                'products' => array_map(static fn(Product $p) => $p->toArray(), self::$products),
                'meta' => self::$meta,
                'path' => self::$loadedFrom,
                'loadedAt' => self::$loadedAt,
            ]);
        }

        return self::$products;
    }

    /** @return array<string, mixed> */
    public static function getInfo(): array
    {
        try {
            self::getAllProducts();
        } catch (\Throwable $e) {
            return [
                'path' => self::$loadedFrom,
                'count' => 0,
                'shopName' => null,
                'shopUrl' => null,
                'loadedAt' => null,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'path' => self::$loadedFrom,
            'count' => self::$meta['count'] ?? count(self::$products ?? []),
            'shopName' => self::$meta['shopName'] ?? null,
            'shopUrl' => self::$meta['shopUrl'] ?? null,
            'loadedAt' => self::$loadedAt ? date('c', self::$loadedAt) : null,
        ];
    }

    public static function formatPrice(Product $product): string
    {
        if ($product->price <= 0) {
            return 'цена на сайте';
        }
        $formatted = number_format($product->price, 0, ',', ' ');
        return $formatted . ' ' . ($product->currency === 'RUB' ? '₽' : $product->currency);
    }

    public static function productLink(Product $product, string $siteUrl): string
    {
        if (preg_match('#^https?://#i', $product->url)) {
            return $product->url;
        }
        $base = rtrim($siteUrl, '/');
        if ($product->url !== '' && $product->url[0] === '/') {
            return $base . $product->url;
        }
        return $base . '/' . ltrim($product->url, '/');
    }

    public static function buildCompactCatalogForAi(string $siteUrl): string
    {
        $lines = [];
        foreach (self::getAllProducts() as $p) {
            $specs = [];
            $n = 0;
            foreach ($p->specs as $k => $v) {
                if ($n++ >= 5) {
                    break;
                }
                $specs[] = $k . ': ' . $v;
            }
            $stock = $p->inStock ? 'в наличии' : 'нет';
            $desc = trim(preg_replace('/\s+/u', ' ', $p->description) ?? '');
            if (mb_strlen($desc) > 100) {
                $desc = mb_substr($desc, 0, 100);
            }
            $tail = implode(' | ', array_filter([implode('; ', $specs), $desc]));
            $line = '[' . $p->id . '] ' . $p->name . ' | ' . $p->category . ' | '
                . self::formatPrice($p) . ' | ' . $stock . ' | ' . self::productLink($p, $siteUrl);
            if ($tail !== '') {
                $line .= ' | ' . $tail;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    public static function formatProductForPrompt(Product $product, string $siteUrl, bool $detailed = false): string
    {
        $specs = [];
        $specList = $product->specs;
        if (!$detailed && count($specList) > 5) {
            $specList = array_slice($specList, 0, 5, true);
        }
        foreach ($specList as $k => $v) {
            $specs[] = "  - {$k}: {$v}";
        }
        $specsText = $specs ? "Характеристики:\n" . implode("\n", $specs) : '';
        $stock = $product->inStock ? 'в наличии' : 'нет в наличии';
        $descLimit = $detailed ? 800 : 120;
        $desc = mb_substr($product->description, 0, $descLimit);

        $lines = [
            $product->name,
            'Категория: ' . $product->category,
            'Цена: ' . self::formatPrice($product) . ' | ' . $stock,
            'Ссылка: ' . self::productLink($product, $siteUrl),
            $specsText,
            $desc ? "Описание из каталога:\n{$desc}" : '',
        ];

        return implode("\n", array_filter($lines));
    }

    private static function resolveCatalogUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return rtrim(Config::siteUrl(), '/') . '/' . ltrim($url, '/');
    }

    private static function fetchXml(string $url): string
    {
        $client = new HttpClient(['socketTimeout' => 120, 'streamTimeout' => 120]);
        $client->setHeader('User-Agent', 'Draxter-AI-Chat/1.0 (Bitrix)');
        $client->setHeader('Accept', 'application/xml,text/xml,*/*');
        $result = $client->get($url);
        if ($result === false || $client->getStatus() >= 400) {
            throw new \RuntimeException('Не удалось загрузить каталог (' . $client->getStatus() . '): ' . $url);
        }
        return $result;
    }
}
