@extends('emails.layout')

@section('title', 'Redefinir senha - Vector Pagamentos')

@section('content')
    <h1 style="font-size: 22px; color: #1a1a2e; margin: 0 0 16px;">Redefinicao de senha</h1>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Ola, <strong>{{ $userName }}</strong>!</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">
        Recebemos uma solicitacao para redefinir a senha da sua conta
        na Vector Pagamentos. Clique no botao abaixo para criar uma nova senha:
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 20px auto;">
        <tr>
            <td align="center" style="background-color: #6c5ce7; border-radius: 8px;">
                <a href="{{ $resetUrl }}" style="display: inline-block; color: #ffffff; text-decoration: none; padding: 14px 32px; font-size: 15px; font-weight: 600;">Redefinir minha senha</a>
            </td>
        </tr>
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f8f9fa; border-radius: 8px; border-left: 4px solid #6c5ce7; margin: 20px 0;">
        <tr>
            <td style="padding: 20px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 6px 0; font-size: 14px; color: #888888; width: 140px;">Validade</td>
                        <td style="padding: 6px 0; font-size: 14px; color: #333333; font-weight: 600;">Este link expira em 1 hora</td>
                    </tr>
                    <tr>
                        <td style="padding: 6px 0; font-size: 14px; color: #888888; width: 140px;">Seguranca</td>
                        <td style="padding: 6px 0; font-size: 14px; color: #333333; font-weight: 600;">Se voce nao solicitou, ignore este e-mail</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr style="border: none; height: 1px; background-color: #eeeeee; margin: 24px 0;">

    <p style="font-size: 13px; color: #999999; margin: 0;">
        Se o botao nao funcionar, copie e cole o link abaixo no seu navegador:
    </p>
    <p style="font-size: 12px; color: #6c5ce7; word-break: break-all; margin: 8px 0 0;">
        {{ $resetUrl }}
    </p>
@endsection
