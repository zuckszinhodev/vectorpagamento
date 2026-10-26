@extends('emails.layout')

@section('title', 'Bem-vindo - GorillaPay')

@section('content')
    <h1 style="font-size: 22px; color: #1a1a2e; margin: 0 0 16px;">Bem-vindo à GorillaPay</h1>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Olá, <strong>{{ $userName }}</strong>!</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">
        Que bom ter você com a gente. Sua conta está <strong>ativa e aprovada</strong>, pronta para vender.
    </p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">
        Pelo painel você acompanha suas vendas, saldo e saques, e configura seus produtos e integrações.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 20px auto;">
        <tr>
            <td align="center" style="background-color: #dc2626; border-radius: 8px;">
                <a href="{{ $dashboardUrl }}" style="display: inline-block; color: #ffffff; text-decoration: none; padding: 14px 32px; font-size: 15px; font-weight: 600;">Acessar meu painel</a>
            </td>
        </tr>
    </table>

    <hr style="border: none; height: 1px; background-color: #eeeeee; margin: 24px 0;">

    <p style="font-size: 13px; color: #999999; margin: 0;">Boas vendas!<br>Equipe GorillaPay</p>
@endsection
