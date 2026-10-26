@extends('emails.layout')

@section('title', 'Verificação KYC - GorillaPay')

@section('content')
    <h1 style="font-size: 22px; color: #1a1a2e; margin: 0 0 16px;">Verificação KYC: ajustes necessários</h1>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Olá, <strong>{{ $userName }}</strong>!</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Infelizmente sua verificação KYC não foi aprovada.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f8f9fa; border-radius: 8px; border-left: 4px solid #dc2626; margin: 20px 0;">
        <tr>
            <td style="padding: 20px; font-size: 14px; color: #333333;">
                <strong>Motivo:</strong> {{ $reason }}
            </td>
        </tr>
    </table>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Você pode corrigir os dados e reenviar sua documentação:</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 20px auto;">
        <tr>
            <td align="center" style="background-color: #dc2626; border-radius: 8px;">
                <a href="{{ $resubmitUrl }}" style="display: inline-block; color: #ffffff; text-decoration: none; padding: 14px 32px; font-size: 15px; font-weight: 600;">Reenviar documentos</a>
            </td>
        </tr>
    </table>

    <hr style="border: none; height: 1px; background-color: #eeeeee; margin: 24px 0;">

    <p style="font-size: 13px; color: #999999; margin: 0;">Se tiver dúvidas, entre em contato com nosso suporte.</p>
@endsection
