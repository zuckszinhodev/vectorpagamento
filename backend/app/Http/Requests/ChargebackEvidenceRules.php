<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class ChargebackEvidenceRules
{
    public static function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:20000'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'delivery_proof' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['required', 'file', 'mimes:png,jpg,jpeg,pdf', 'max:5120'],
            'med' => ['nullable', 'array:end_to_end_id,protocol,institution,order_reference,payer_relationship,fulfillment,customer_contact,refund_details,business_type,refund_status,confirmed'],
            'med.end_to_end_id' => ['nullable', 'string', 'max:32'],
            'med.protocol' => ['nullable', 'string', 'max:100'],
            'med.institution' => ['nullable', 'string', 'max:150'],
            'med.order_reference' => ['nullable', 'string', 'max:1000'],
            'med.payer_relationship' => ['nullable', 'string', 'max:3000'],
            'med.fulfillment' => ['nullable', 'string', 'max:5000'],
            'med.customer_contact' => ['nullable', 'string', 'max:5000'],
            'med.refund_details' => ['nullable', 'string', 'max:3000'],
            'med.business_type' => ['required_with:med', 'in:physical,digital,service,other'],
            'med.refund_status' => ['required_with:med', 'in:none,partial,full,pending'],
            'med.confirmed' => ['exclude_without:med', 'required_with:med', 'accepted'],
        ];
    }
}
