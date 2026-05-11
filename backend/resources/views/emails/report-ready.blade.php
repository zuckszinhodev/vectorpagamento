<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #333; line-height: 1.5; margin: 0; padding: 20px;">

<p>Olá {{ $userName }},</p>

<p>Seu relatório de <strong>{{ $reportType }}</strong> está pronto para download.</p>

<p><a href="{{ $downloadUrl }}" style="display: inline-block; padding: 10px 20px; background-color: #3b82f6; color: #fff; text-decoration: none; border-radius: 6px;">Baixar Relatório</a></p>

<p><em>O link expira em 24 horas.</em></p>

<p>Atenciosamente,<br>Equipe Vector Pagamentos</p>

</body>
</html>
