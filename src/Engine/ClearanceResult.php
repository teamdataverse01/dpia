<?php

declare(strict_types=1);

namespace App\Engine;

use App\Domain\ControlCategory;

final class ClearanceResult
{
    /**
     * @param list<ControlCategory> $cleared
     * @param array<string,list<string>> $reasons category value => plain-language reasons it is NOT cleared (empty when cleared)
     * @param array<int,list<string>> $controlGaps control id => unmet criteria
     */
    public function __construct(
        public readonly array $cleared,
        public readonly array $reasons,
        public readonly array $controlGaps,
    ) {
    }

    public function isCleared(ControlCategory $c): bool
    {
        return in_array($c, $this->cleared, true);
    }

    /** @return list<string> */
    public function clearedValues(): array
    {
        return array_map(fn (ControlCategory $c) => $c->value, $this->cleared);
    }

    public function toArray(): array
    {
        $out = [];
        foreach (ControlCategory::cases() as $c) {
            $out[$c->value] = [
                'label' => $c->label(),
                'cleared' => $this->isCleared($c),
                'why_not' => $this->reasons[$c->value] ?? [],
            ];
        }
        return $out;
    }
}
