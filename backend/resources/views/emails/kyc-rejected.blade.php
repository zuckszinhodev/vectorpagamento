<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #333; line-height: 1.5; margin: 0; padding: 20px;">

<p>Olá {{ $userName }},</p>

<p>Infelizmente sua verificação KYC não foi aprovada.</p>

<p><strong>Motivo:</strong> {{ $reason }}</p>

<p>Você pode corrigir os dados e reenviar sua documentação:</p>

<p><a href="{{ $resubmitUrl }}" style="display: inline-block; padding: 10px 20px; background-color: #3b82f6; color: #fff; text-decoration: none; border-radius: 6px;">Reenviar Documentos</a></p>

<p>Se tiver dúvidas, entre em contato com nosso suporte.</p>

<p>Atenciosamente,<br>Equipe GatePro</p>

</body>
</html>
