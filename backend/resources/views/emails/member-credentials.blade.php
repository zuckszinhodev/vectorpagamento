<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #333; line-height: 1.5; margin: 0; padding: 20px;">

<p>Olá {{ $memberName }},</p>

<p>Seu acesso ao curso <strong>{{ $courseName }}</strong> foi liberado!</p>

<p>Use as credenciais abaixo para acessar:</p>

<div style="background-color: #f3f4f6; padding: 16px; border-radius: 8px; margin: 16px 0;">
    <p style="margin: 4px 0;"><strong>Email:</strong> {{ $memberEmail }}</p>
    <p style="margin: 4px 0;"><strong>Senha temporária:</strong> {{ $tempPassword }}</p>
</div>

<p><a href="{{ $loginUrl }}" style="display: inline-block; padding: 10px 20px; background-color: #3b82f6; color: #fff; text-decoration: none; border-radius: 6px;">Acessar Área de Membros</a></p>

<p><em>Recomendamos que altere sua senha ao fazer o primeiro login.</em></p>

<p>Atenciosamente,<br>Equipe Vector Pagamentos</p>

</body>
</html>
