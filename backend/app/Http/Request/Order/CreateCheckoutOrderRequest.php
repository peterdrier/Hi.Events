<?php

namespace HiEvents\Http\Request\Order;

use HiEvents\Http\Request\BaseRequest;
use HiEvents\Locale;
use HiEvents\Validators\Rules\PersonNameRule;
use HiEvents\Validators\Rules\RulesHelper;
use Illuminate\Validation\Rule;

class CreateCheckoutOrderRequest extends BaseRequest
{
    private const ATTENDEE_NAME_MAX_LENGTH = 255;

    public function rules(): array
    {
        return [
            'external_reference' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:'.PersonNameRule::MAX_LENGTH, new PersonNameRule],
            'last_name' => ['nullable', 'string', 'max:'.PersonNameRule::MAX_LENGTH, new PersonNameRule],
            'email' => ['required', 'email'],
            'locale' => ['required', Rule::in(Locale::getSupportedLocales())],
            'expires_at' => ['required', 'date', 'after:now'],
            'return_url' => ['nullable', 'url:https,http', 'max:2048'],
            'send_buyer_emails' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_price_id' => ['nullable', 'integer'],
            'items.*.event_occurrence_id' => ['nullable', 'integer'],
            'items.*.price' => ['required', ...RulesHelper::MONEY],
            'items.*.attendee' => ['nullable', 'array'],
            'items.*.attendee.first_name' => ['required_with:items.*.attendee', 'string', 'max:'.self::ATTENDEE_NAME_MAX_LENGTH, new PersonNameRule],
            'items.*.attendee.last_name' => ['nullable', 'string', 'max:'.self::ATTENDEE_NAME_MAX_LENGTH, new PersonNameRule],
            'items.*.attendee.email' => ['nullable', 'email'],
        ];
    }
}
