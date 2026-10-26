@extends('emails.layout')

@section('title', 'Acesso liberado - GorillaPay')

@section('content')
    <h1 style="font-size: 22px; color: #1a1a2e; margin: 0 0 16px;">Seu acesso foi liberado</h1>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Olá, <strong>{{ $memberName }}</strong>!</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">
        Seu acesso ao curso <strong>{{ $courseName }}</strong> foi liberado. Use as credenciais abaixo para entrar:
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f8f9fa; border-radius: 8px; border-left: 4px solid #dc2626; margin: 20px 0;">
        <tr>
            <td style="padding: 20px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding: 6px 0; font-size: 14px; color: #888888; width: 140px;">E-mail</td>
                        <td style="padding: 6px 0; font-size: 14px; color: #333333; font-weight: 600;">{{ $memberEmail }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 6px 0; font-size: 14px; color: #888888; width: 140px;">Senha temporária</td>
                        <td style="padding: 6px 0; font-size: 14px; color: #333333; font-weight: 600;">{{ $tempPassword }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 20px auto;">
        <tr>
            <td align="center" style="background-color: #dc2626; border-radius: 8px;">
                <a href="{{ $loginUrl }}" style="display: inline-block; color: #ffffff; text-decoration: none; padding: 14px 32px; font-size: 15px; font-weight: 600;">Acessar Área de Membros</a>
            </td>
        </tr>
    </table>

    <hr style="border: none; height: 1px; background-color: #eeeeee; margin: 24px 0;">

    <p style="font-size: 13px; color: #999999; margin: 0;">Recomendamos que altere sua senha ao fazer o primeiro login.</p>
@endsection
