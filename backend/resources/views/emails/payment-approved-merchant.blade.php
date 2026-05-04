@extends('emails.layout')

@section('title', 'Nova venda aprovada - GatePro')

@section('content')
    <h1 style="font-size: 22px; color: #1a1a2e; margin: 0 0 16px;">Nova venda aprovada</h1>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Ola, <strong>{{ $merchantName }}</strong>!</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Voce recebeu um novo pagamento na sua conta GatePro.</p>

    <p style="font-size: 28px; font-weight: 800; color: #27ae60; text-align: center; margin: 16px 0;">
        R$ {{ $amount }}
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f8f9fa; border-radius: 8px; border-left: 4px solid #6c5ce7; margin: 20px 0;">
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
                        <td style="padding: 6px 0; font-size: 14px; color: #888888; width: 140px;">Cliente</td>
                        <td style="padding: 6px 0; font-size: 14px; color: #333333; font-weight: 600;">{{ $customerName }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 6px 0; font-size: 14px; color: #888888; width: 140px;">E-mail</td>
                        <td style="padding: 6px 0; font-size: 14px; color: #333333; font-weight: 600;">{{ $customerEmail }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 6px 0; font-size: 14px; color: #888888; width: 140px;">Produto</td>
                        <td style="padding: 6px 0; font-size: 14px; color: #333333; font-weight: 600;">{{ $productDescription }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 6px 0; font-size: 14px; color: #888888; width: 140px;">Data</td>
                        <td style="padding: 6px 0; font-size: 14px; color: #333333; font-weight: 600;">{{ $paidAt }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 20px auto;">
        <tr>
            <td align="center" style="background-color: #6c5ce7; border-radius: 8px;">
                <a href="{{ $dashboardUrl }}" style="display: inline-block; color: #ffffff; text-decoration: none; padding: 14px 32px; font-size: 15px; font-weight: 600;">Ver no Dashboard</a>
            </td>
        </tr>
    </table>

    <hr style="border: none; height: 1px; background-color: #eeeeee; margin: 24px 0;">

    <p style="font-size: 13px; color: #999999; margin: 0;">
        O valor sera creditado na sua carteira GatePro automaticamente
        (descontadas as taxas aplicaveis).
    </p>
@endsection
