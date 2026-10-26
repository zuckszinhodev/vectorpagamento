@extends('emails.layout')

@section('title', 'Bem-vindo - GorillaPay')

@section('content')
    <h1 style="font-size: 22px; color: #1a1a2e; margin: 0 0 16px;">Bem-vindo à GorillaPay</h1>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Olá, <strong>{{ $userName }}</strong>!</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Sua conta na GorillaPay foi criada com sucesso.</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">
        Para começar a utilizar a plataforma, acesse sua conta e complete a verificação de identidade (KYC).
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 20px auto;">
        <tr>
            <td align="center" style="background-color: #dc2626; border-radius: 8px;">
                <a href="{{ $loginUrl }}" style="display: inline-block; color: #ffffff; text-decoration: none; padding: 14px 32px; font-size: 15px; font-weight: 600;">Acessar minha conta</a>
            </td>
        </tr>
    </table>

    <hr style="border: none; height: 1px; background-color: #eeeeee; margin: 24px 0;">

    <p style="font-size: 13px; color: #999999; margin: 0;">Se você não criou esta conta, ignore este e-mail.</p>
@endsection
