@extends('emails.layout')

@section('title', 'Nova adquirente: Stone | Pagar.me - GorillaPay')

@section('content')
    <a href="{{ $supportUrl }}" style="text-decoration: none;">
        <img src="{{ $bannerUrl }}" width="536" alt="Stone | Pagar.me: nova adquirente disponível na GorillaPay" style="display: block; width: 100%; max-width: 536px; height: auto; border: 0; border-radius: 8px; margin: 0 0 28px;">
    </a>

    <p style="font-size: 16px; color: #1a1a2e; margin: 0 0 16px;">Olá, parceiro(a)! 🦍</p>

    <h1 style="font-size: 20px; color: #1a1a2e; margin: 0 0 16px;">🚀 TEMOS UMA GRANDE NOVIDADE PARA VOCÊ!</h1>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">
        A GorillaPay continua evoluindo para oferecer mais possibilidades, eficiência e soluções para o seu negócio.
    </p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">
        💚 É com satisfação que anunciamos a chegada da nossa nova adquirente: <strong>STONE | PAGAR.ME</strong>!
    </p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 20px;">
        Agora você conta com mais uma opção de processamento de pagamentos integrada à nossa estrutura.
    </p>

    <p style="font-size: 15px; color: #1a1a2e; font-weight: 700; margin: 0 0 10px;">💰 Confira as condições disponíveis:</p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color: #f0fdf4; border-radius: 8px; margin: 0 0 20px;">
        <tr>
            <td style="padding: 16px 20px; font-size: 15px; color: #14532d; line-height: 2;">
                ✅ Até R$ 10.000 por transação<br>
                ✅ 0 MED<br>
                ✅ 0 Desvio<br>
                ✅ Integração com a GorillaPay
            </td>
        </tr>
    </table>

    <p style="font-size: 15px; color: #1a1a2e; font-weight: 700; margin: 0 0 10px;">🔥 Mais possibilidades para o seu negócio!</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 20px;">
        Nosso compromisso é continuar expandindo nossa infraestrutura, oferecendo novas integrações e soluções que acompanhem o crescimento da sua operação.
    </p>

    <p style="font-size: 15px; color: #1a1a2e; font-weight: 700; margin: 0 0 10px;">📲 QUER ATIVAR A NOVA ADQUIRENTE?</p>

    <p style="font-size: 15px; color: #555555; margin: 0 0 14px;">
        Entre em contato com nossa equipe de suporte para consultar as condições e solicitar a ativação.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 20px auto;">
        <tr>
            <td align="center" style="background-color: #dc2626; border-radius: 8px;">
                <a href="{{ $supportUrl }}" style="display: inline-block; color: #ffffff; text-decoration: none; padding: 14px 32px; font-size: 15px; font-weight: 600;">Falar com o suporte</a>
            </td>
        </tr>
    </table>

    <p style="font-size: 15px; color: #555555; margin: 20px 0 14px;">
        Agradecemos por confiar na GorillaPay. Estamos trabalhando constantemente para entregar uma experiência cada vez melhor!
    </p>

    <p style="font-size: 15px; color: #1a1a2e; margin: 24px 0 0;">
        <strong>🦍 GORILLAPAY</strong><br>
        <span style="color: #555555;">Tecnologia, inovação e soluções para quem quer crescer.</span>
    </p>

    <p style="font-size: 15px; color: #555555; margin: 16px 0 0;">Equipe GorillaPay</p>
@endsection
