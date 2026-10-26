import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import QRCode from 'qrcode';
import toast from 'react-hot-toast';
import {
  CheckCircleIcon,
  ClipboardDocumentIcon,
  ClockIcon,
  ExclamationTriangleIcon,
} from '@heroicons/react/24/outline';
import { api } from '@/lib/api';
import PixIcon from '@/components/icons/PixIcon';

interface PixChargeData {
  charge_id: string;
  status: string;
  amount: number;
  description: string | null;
  merchant_name: string | null;
  pix_copy_paste: string | null;
  expires_at: string | null;
  paid_at: string | null;
}

const formatBRL = (cents: number) =>
  (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

function useCountdown(expiresAt: string | null) {
  const [now, setNow] = useState(Date.now());

  useEffect(() => {
    if (!expiresAt) return;
    const id = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(id);
  }, [expiresAt]);

  if (!expiresAt) return null;
  const diff = Math.max(0, new Date(expiresAt).getTime() - now);
  const h = Math.floor(diff / 3_600_000);
  const m = Math.floor((diff % 3_600_000) / 60_000);
  const s = Math.floor((diff % 60_000) / 1000);
  const pad = (n: number) => String(n).padStart(2, '0');
  return { expired: diff === 0, label: h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}` };
}

export default function PixPaymentPage() {
  const { chargeId } = useParams<{ chargeId: string }>();
  const [qrCodeUrl, setQrCodeUrl] = useState('');
  const [copied, setCopied] = useState(false);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['public-pix-charge', chargeId],
    queryFn: async () => {
      const response = await api.get(`/checkout/charge/${chargeId}/pix`);
      return response.data.data as PixChargeData;
    },
    enabled: !!chargeId,
    retry: false,
    // Enquanto aguarda pagamento, consulta a cada 5s para mostrar a confirmação sozinho
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      return status === 'pending' || status === 'processing' ? 5000 : false;
    },
  });

  const countdown = useCountdown(data?.expires_at ?? null);
  const isPaid = data?.status === 'approved';
  const isExpired = data?.status === 'expired' || (!isPaid && countdown?.expired);
  const isPending = !isPaid && !isExpired && (data?.status === 'pending' || data?.status === 'processing');

  useEffect(() => {
    if (!data?.pix_copy_paste) return;
    QRCode.toDataURL(data.pix_copy_paste, { width: 320, margin: 1 })
      .then(setQrCodeUrl)
      .catch(() => setQrCodeUrl(''));
  }, [data?.pix_copy_paste]);

  const handleCopy = () => {
    if (!data?.pix_copy_paste) return;
    navigator.clipboard
      .writeText(data.pix_copy_paste)
      .then(() => {
        setCopied(true);
        toast.success('Código PIX copiado!');
        window.setTimeout(() => setCopied(false), 2500);
      })
      .catch(() => toast.error('Não foi possível copiar. Selecione o código e copie manualmente.'));
  };

  return (
    <div className="min-h-screen bg-neutral-100 px-4 py-8 text-neutral-900 sm:py-14">
      <div className="mx-auto w-full max-w-md">
        <div className="overflow-hidden rounded-3xl bg-white shadow-xl shadow-neutral-900/5 ring-1 ring-neutral-200">
          {isLoading ? (
            <div className="flex flex-col items-center gap-4 px-6 py-16">
              <div className="h-8 w-8 animate-spin rounded-full border-2 border-neutral-200 border-t-emerald-500" />
              <p className="text-sm text-neutral-500">Carregando cobrança...</p>
            </div>
          ) : isError || !data ? (
            <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
              <ExclamationTriangleIcon className="h-10 w-10 text-amber-500" />
              <h1 className="text-lg font-semibold">Cobrança não encontrada</h1>
              <p className="text-sm text-neutral-500">Confira o link com quem enviou a cobrança.</p>
            </div>
          ) : (
            <>
              <div className="border-b border-neutral-100 px-6 pb-6 pt-7 text-center">
                {data.merchant_name && (
                  <p className="text-xs font-semibold uppercase tracking-wider text-neutral-500">
                    {data.merchant_name}
                  </p>
                )}
                <p className="mt-2 text-4xl font-bold tracking-tight">{formatBRL(data.amount)}</p>
                {data.description && <p className="mt-2 text-sm text-neutral-500">{data.description}</p>}
              </div>

              {isPaid && (
                <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
                  <CheckCircleIcon className="h-14 w-14 text-emerald-500" />
                  <h1 className="text-xl font-semibold">Pagamento confirmado</h1>
                  <p className="text-sm text-neutral-500">Obrigado! Você já pode fechar esta página.</p>
                </div>
              )}

              {isExpired && (
                <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
                  <ClockIcon className="h-12 w-12 text-neutral-400" />
                  <h1 className="text-lg font-semibold">Este PIX expirou</h1>
                  <p className="text-sm text-neutral-500">Peça um novo link de pagamento para quem enviou a cobrança.</p>
                </div>
              )}

              {!isPaid && !isExpired && !isPending && (
                <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
                  <ExclamationTriangleIcon className="h-12 w-12 text-neutral-400" />
                  <h1 className="text-lg font-semibold">Cobrança indisponível</h1>
                  <p className="text-sm text-neutral-500">Esta cobrança não pode mais ser paga.</p>
                </div>
              )}

              {isPending && (
                <div className="space-y-6 px-6 py-6">
                  <div className="flex items-center justify-center gap-2 text-sm text-neutral-600">
                    <span className="relative flex h-2.5 w-2.5">
                      <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75" />
                      <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-amber-500" />
                    </span>
                    Aguardando pagamento
                    {countdown && <span className="font-mono font-medium text-neutral-900">· {countdown.label}</span>}
                  </div>

                  {qrCodeUrl && (
                    <div className="mx-auto w-fit rounded-2xl border border-neutral-200 p-3">
                      <img src={qrCodeUrl} alt="QR Code PIX" className="h-56 w-56" />
                    </div>
                  )}

                  {data.pix_copy_paste && (
                    <div className="space-y-2">
                      <p className="text-center text-xs font-medium text-neutral-500">PIX copia e cola</p>
                      <div className="break-all rounded-xl bg-neutral-50 px-4 py-3 font-mono text-[11px] leading-relaxed text-neutral-600 ring-1 ring-neutral-200">
                        {data.pix_copy_paste}
                      </div>
                      <button
                        type="button"
                        onClick={handleCopy}
                        className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-semibold text-white transition-colors hover:bg-emerald-700"
                      >
                        {copied ? <CheckCircleIcon className="h-5 w-5" /> : <ClipboardDocumentIcon className="h-5 w-5" />}
                        {copied ? 'Código copiado' : 'Copiar código PIX'}
                      </button>
                    </div>
                  )}

                  <ol className="space-y-2 rounded-xl bg-neutral-50 px-4 py-4 text-sm text-neutral-600">
                    <li><span className="font-semibold text-neutral-900">1.</span> Abra o app do seu banco e entre na área PIX.</li>
                    <li><span className="font-semibold text-neutral-900">2.</span> Escaneie o QR Code ou escolha "PIX copia e cola".</li>
                    <li><span className="font-semibold text-neutral-900">3.</span> Confira o valor e confirme. Esta página atualiza sozinha.</li>
                  </ol>
                </div>
              )}
            </>
          )}
        </div>

        <p className="mt-5 flex items-center justify-center gap-1.5 text-xs text-neutral-400">
          <PixIcon className="h-3.5 w-3.5" />
          Pagamento instantâneo via PIX
        </p>
      </div>
    </div>
  );
}
