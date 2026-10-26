@php($frontendUrl = rtrim(config('app.frontend_url', 'https://gorillapay.tech'), '/'))
<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('title', 'GorillaPay')</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; background-color: #f4f4f5; color: #333333; line-height: 1.6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f5;">
        <tr>
            <td align="center" style="padding: 24px 10px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; width: 100%;">
                    <!-- Header -->
                    <tr>
                        <td align="center" bgcolor="#0a0a0a" style="background-color: #0a0a0a; border-radius: 12px 12px 0 0; border-top: 4px solid #dc2626; padding: 24px 20px;">
                            <a href="{{ $frontendUrl }}" style="text-decoration: none;">
                                <img src="{{ $frontendUrl }}/img/gorillapay-email-logo.png?v=2" width="210" alt="GorillaPay" style="display: block; width: 210px; max-width: 100%; height: auto; border: 0; color: #ffffff; font-size: 26px; font-weight: 800;">
                            </a>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="background-color: #ffffff; border-radius: 0 0 12px 12px; padding: 40px 32px;">
                            @yield('content')
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding: 24px 0; font-size: 12px; color: #999999;">
                            <p style="margin: 0;">&copy; {{ date('Y') }} GorillaPay. Todos os direitos reservados.</p>
                            <p style="margin: 8px 0 0;">
                                Esta é uma mensagem automática, por favor não responda.
                            </p>
                            <p style="margin: 8px 0 0;">
                                <a href="{{ $frontendUrl }}" style="color: #dc2626; text-decoration: none;">gorillapay.tech</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
