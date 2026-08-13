<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isGuest();
    }

    /**
     * クラス別人数（counts[premium]=1 など）を明細リスト items[] に正規化する。
     * 0人のクラスは落とす。
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('counts')) {
            $nominate = (array) $this->input('nominate', []);
            $items = [];
            foreach ((array) $this->input('counts') as $class => $count) {
                $count = (int) $count;
                if ($count > 0) {
                    $items[] = [
                        'class' => $class,
                        'headcount' => $count,
                        'nominated' => (bool) ($nominate[$class] ?? false),
                    ];
                }
            }
            $this->merge(['items' => $items]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'area_id' => ['required', 'integer', 'exists:areas,id'],
            'start_offset_min' => ['required', 'integer', 'in:30,60,90,120'],
            'duration_min' => ['required', 'integer', 'in:60,90,120,150,180'],
            'venue_kind' => ['required', Rule::in(['restaurant', 'bar', 'public'])], // 密室禁止(法務04)
            'is_night' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.class' => ['required', Rule::in(['premium', 'vip', 'royal_vip'])],
            'items.*.headcount' => ['required', 'integer', 'min:1', 'max:10'],
            'items.*.nominated' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'キャストのクラスと人数を1名以上指定してください。',
            'items.min' => 'キャストのクラスと人数を1名以上指定してください。',
        ];
    }

    /**
     * @return list<array{class:string,headcount:int,nominated:bool}>
     */
    public function lineItems(): array
    {
        return array_values(array_map(static fn (array $i) => [
            'class' => $i['class'],
            'headcount' => (int) $i['headcount'],
            'nominated' => (bool) ($i['nominated'] ?? false),
        ], $this->validated('items')));
    }
}
