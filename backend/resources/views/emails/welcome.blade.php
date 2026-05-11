<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #333; line-height: 1.5; margin: 0; padding: 20px;">

<p>Ola {{ $userName }}, Sua conta na Vector Pagamentos foi criada com sucesso.</p>

<p>Para comecar a utilizar a plataforma, acesse sua conta e complete a verificacao de identidade (KYC).</p>

<p><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></p>

<p>Se voce nao criou esta conta, ignore este email.</p>

<p>Atenciosamente,<br>Equipe Vector Pagamentos</p>

</body>
</html>
