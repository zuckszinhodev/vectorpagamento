export const medFields = [
  { key: 'end_to_end_id', label: 'Identificador do Pix (EndToEndId)', hint: 'Copie do comprovante Pix, se disponível. Não use o ID interno da cobrança.', max: 32 },
  { key: 'protocol', label: 'Protocolo MED na instituição', hint: 'Informe o protocolo recebido do banco ou PSP, se houver.', max: 100 },
  { key: 'institution', label: 'Banco ou instituição responsável', hint: 'Instituição com a qual você está tratando a contestação.', max: 150 },
  { key: 'order_reference', label: 'Pedido, contrato ou nota fiscal', hint: 'Número de referência e descrição do produto ou serviço vendido.', max: 1000, multiline: true },
  { key: 'payer_relationship', label: 'Relação entre pagador e comprador', hint: 'Explique quem fez o pagamento e eventual diferença entre pagador e comprador.', max: 3000, multiline: true },
  { key: 'fulfillment', label: 'Entrega, prestação do serviço ou acesso digital', hint: 'Informe datas, destinatário, confirmação de recebimento ou registros de acesso e uso.', max: 5000, multiline: true },
  { key: 'customer_contact', label: 'Histórico de contato com o cliente', hint: 'Resuma conversas, datas, canais, reclamações e tentativas de solução. Relacione os anexos.', max: 5000, multiline: true },
  { key: 'refund_details', label: 'Detalhes de cancelamento ou devolução', hint: 'Se houve devolução, informe valor, data e identificador e anexe o comprovante.', max: 3000, multiline: true },
] as const;

export type MedEvidence = Record<string, string>;

export function MedEvidenceFields({ value, onChange }: { value: MedEvidence; onChange: (value: MedEvidence) => void }) {
  const update = (key: string, next: string) => onChange({ ...value, [key]: next });
  return (
    <section className="space-y-5 rounded-xl border border-border-DEFAULT p-4 sm:p-6">
      <div>
        <h3 className="text-base font-semibold text-tx-primary">Informações para a defesa MED</h3>
        <p className="mt-1 text-sm text-tx-secondary">Preencha o que se aplica ao caso. Campos com * são obrigatórios neste formulário; a instituição pode solicitar documentos adicionais.</p>
      </div>
      <div className="grid gap-5 sm:grid-cols-2">
        <div>
          <label htmlFor="med-business_type" className="mb-2 block text-sm font-medium text-tx-primary">Tipo de operação *</label>
          <select id="med-business_type" required className="input h-12" value={value.business_type || ''} onChange={e => update('business_type', e.target.value)}>
            <option value="">Selecione</option>
            <option value="physical">Produto físico</option><option value="digital">Produto digital</option><option value="service">Serviço</option><option value="other">Outra operação</option>
          </select>
        </div>
        <div>
          <label htmlFor="med-refund_status" className="mb-2 block text-sm font-medium text-tx-primary">Situação da devolução *</label>
          <select id="med-refund_status" required className="input h-12" value={value.refund_status || ''} onChange={e => update('refund_status', e.target.value)}>
            <option value="">Selecione</option><option value="none">Não houve devolução</option><option value="partial">Devolução parcial</option><option value="full">Devolução integral</option><option value="pending">Solicitação em andamento</option>
          </select>
        </div>
        {medFields.map(field => (
          <div key={field.key} className={'multiline' in field ? 'sm:col-span-2' : ''}>
            <label htmlFor={`med-${field.key}`} className="mb-2 block text-sm font-medium text-tx-primary">{field.label}</label>
            {'multiline' in field ? (
              <textarea id={`med-${field.key}`} aria-describedby={`med-${field.key}-hint`} rows={3} maxLength={field.max} className="input h-auto min-h-[100px] resize-y leading-relaxed" value={value[field.key] || ''} onChange={e => update(field.key, e.target.value)} />
            ) : (
              <input id={`med-${field.key}`} aria-describedby={`med-${field.key}-hint`} maxLength={field.max} className="input h-12" value={value[field.key] || ''} onChange={e => update(field.key, e.target.value)} />
            )}
            <p id={`med-${field.key}-hint`} className="mt-1.5 text-xs text-tx-muted">{field.hint}</p>
          </div>
        ))}
      </div>
    </section>
  );
}

export function MedEvidenceSummary({ value }: { value: MedEvidence }) {
  const labels: Record<string, string> = { physical: 'Produto físico', digital: 'Produto digital', service: 'Serviço', other: 'Outra operação', none: 'Não houve devolução', partial: 'Devolução parcial', full: 'Devolução integral', pending: 'Solicitação em andamento' };
  return <section className="space-y-4 rounded-xl border border-border-DEFAULT p-5">
    <h3 className="font-semibold text-tx-primary">Defesa MED • Pix</h3>
    {[{ key: 'business_type', label: 'Tipo de operação' }, { key: 'refund_status', label: 'Situação da devolução' }, ...medFields].map(field => value[field.key] ? (
      <div key={field.key}><p className="text-xs text-tx-muted">{field.label}</p><p className="mt-1 whitespace-pre-wrap break-words text-sm text-tx-primary">{['business_type', 'refund_status'].includes(field.key) ? labels[value[field.key]] || value[field.key] : value[field.key]}</p></div>
    ) : null)}
  </section>;
}
