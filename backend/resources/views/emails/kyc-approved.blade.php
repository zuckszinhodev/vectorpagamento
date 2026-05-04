<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #333; line-height: 1.5; margin: 0; padding: 20px;">

<p>Ola {{ $userName }},</p>

<p>Parabens! Sua conta foi aprovada com sucesso.</p>

<p>Voce ja pode acessar todos os recursos da plataforma pelo seu dashboard:</p>

<p><a href="{{ $dashboardUrl }}">{{ $dashboardUrl }}</a></p>

<p>Atenciosamente,<br>Equipe GatePro</p>

</body>
</html>
