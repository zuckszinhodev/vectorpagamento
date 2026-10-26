@extends('emails.layout')

@section('title', 'Conta aprovada - GorillaPay')

@section('content')
    <h1 style="font-size: 22px; color: #1a1a2e; margin: 0 0 16px;">Sua conta foi aprovada</h1>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">Olá, <strong>{{ $userName }}</strong>!</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">
        Parabéns! Sua verificação foi concluída e sua conta foi aprovada com sucesso.
        Você já pode acessar todos os recursos da plataforma pelo seu dashboard.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 20px auto;">
        <tr>
            <td align="center" style="background-color: #dc2626; border-radius: 8px;">
                <a href="{{ $dashboardUrl }}" style="display: inline-block; color: #ffffff; text-decoration: none; padding: 14px 32px; font-size: 15px; font-weight: 600;">Ir para o Dashboard</a>
            </td>
        </tr>
    </table>
@endsection
