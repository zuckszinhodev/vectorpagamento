@extends('emails.layout')

@section('title', 'Verifique seu e-mail - GorillaPay')

@section('content')
    <h1 style="font-size: 22px; color: #1a1a2e; margin: 0 0 16px;">Confirme seu e-mail</h1>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Olá, <strong>{{ $userName }}</strong>!</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">
        Obrigado por se cadastrar na GorillaPay. Para completar seu registro, confirme seu e-mail clicando no botão abaixo:
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 20px auto;">
        <tr>
            <td align="center" style="background-color: #dc2626; border-radius: 8px;">
                <a href="{{ $verificationUrl }}" style="display: inline-block; color: #ffffff; text-decoration: none; padding: 14px 32px; font-size: 15px; font-weight: 600;">Verificar e-mail</a>
            </td>
        </tr>
    </table>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Este link expira em <strong>24 horas</strong>.</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Se você não criou uma conta na GorillaPay, ignore este e-mail.</p>

    <hr style="border: none; height: 1px; background-color: #eeeeee; margin: 24px 0;">

    <p style="font-size: 13px; color: #999999; margin: 0;">
        Se o botão não funcionar, copie e cole o link abaixo no seu navegador:
    </p>
    <p style="font-size: 12px; color: #dc2626; word-break: break-all; margin: 8px 0 0;">
        {{ $verificationUrl }}
    </p>
@endsection
