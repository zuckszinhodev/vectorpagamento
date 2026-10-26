@extends('emails.layout')

@section('title', 'Pagamento confirmado - GorillaPay')

@section('content')
    <h1 style="font-size: 22px; color: #1a1a2e; margin: 0 0 16px;">Pagamento confirmado</h1>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Ola, <strong>{{ $customerName }}</strong>!</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">
        Seu pagamento foi processado e confirmado com sucesso.
        Aqui estao os detalhes da sua compra:
    </p>

    <p style="font-size: 28px; font-weight: 800; color: #27ae60; text-align: center; margin: 16px 0;">
        R$ {{ $amount }}
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f8f9fa; border-radius: 8px; border-left: 4px solid #dc2626; margin: 20px 0;">
        <tr>
            <td style="padding: 20px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 6px 0; font-size: 14px; color: #888888; width: 140px;">Pedido</td>
                        <td style="padding: 6px 0; font-size: 14px; color: #333333; font-weight: 600;">#{{ substr($chargeId, 0, 8) }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 6px 0; font-size: 14px; color: #888888; width: 140px;">Pagamento</td>
                        <td style="padding: 6px 0; font-size: 14px; color: #333333; font-weight: 600;">{{ $paymentMethod }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 6px 0; font-size: 14px; color: #888888; width: 140px;">Data</td>
                        <td style="padding: 6px 0; font-size: 14px; color: #333333; font-weight: 600;">{{ $paidAt }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">
        Guarde este e-mail como comprovante da sua compra.
        Caso tenha alguma duvida, entre em contato com o vendedor.
    </p>

    <hr style="border: none; height: 1px; background-color: #eeeeee; margin: 24px 0;">

    <p style="font-size: 13px; color: #999999; margin: 0;">
        Este e um recibo automatico do seu pagamento processado pela GorillaPay.
    </p>
@endsection
