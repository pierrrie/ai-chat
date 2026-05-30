<?php

namespace Draxter\Aichat;

class Product
{
    public string $id;
    public string $name;
    public string $category;
    public float $price;
    public string $currency;
    public string $url;
    public bool $inStock;
    public string $description;
    /** @var array<string, string> */
    public array $specs;
    /** @var string[] */
    public array $tags;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $p = new self();
        $p->id = (string)($data['id'] ?? '');
        $p->name = (string)($data['name'] ?? '');
        $p->category = (string)($data['category'] ?? '');
        $p->price = (float)($data['price'] ?? 0);
        $p->currency = (string)($data['currency'] ?? 'RUB');
        $p->url = (string)($data['url'] ?? '');
        $p->inStock = (bool)($data['inStock'] ?? true);
        $p->description = (string)($data['description'] ?? '');
        $p->specs = is_array($data['specs'] ?? null) ? $data['specs'] : [];
        $p->tags = is_array($data['tags'] ?? null) ? array_values($data['tags']) : [];
        return $p;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'price' => $this->price,
            'currency' => $this->currency,
            'url' => $this->url,
            'inStock' => $this->inStock,
            'description' => $this->description,
            'specs' => $this->specs,
            'tags' => $this->tags,
        ];
    }
}
