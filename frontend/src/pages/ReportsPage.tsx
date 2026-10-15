import { useState } from 'react';
import { Calendar, Download, FileText, Filter } from 'lucide-react';
import toast from 'react-hot-toast';
import { api } from '../lib/api';
import { safeHref } from '../lib/safeUrl';

interface Report {
  id: string;
  type: string;
  name: string;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  created_at: string;
  file_url?: string;
}

export default function ReportsPage() {
  const [reports, setReports] = useState<Report[]>([]);
  const [generating, setGenerating] = useState(false);

  const reportTypes = [
    { id: 'charges', name: 'Relatório de Cobranças', description: 'Todas as transações do período' },
    { id: 'transactions', name: 'Relatório de Transações', description: 'Detalhamento financeiro' },
    { id: 'splits', name: 'Relatório de Splits', description: 'Distribuição de valores' },
    { id: 'refunds', name: 'Relatório de Reembolsos', description: 'Estornos e devoluções' },
    { id: 'affiliates', name: 'Relatório de Afiliados', description: 'Comissões e vendas' },
  ];

  const generateReport = async (type: string) => {
    setGenerating(true);
    try {
      const response = await api.post('/reports/generate', {
        type,
        format: 'csv',
        filters: {
          start_date: new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString(),
          end_date: new Date().toISOString(),
        },
      });
      if (response.data?.data) {
        setReports(prev => [response.data.data, ...prev]);
      }
      toast.success('Relatório gerado com sucesso!');
    } catch (error: any) {
      toast.error(error.response?.data?.error?.message || 'Erro ao gerar relatório');
    } finally {
      setGenerating(false);
    }
  };

  return (
    <div className="p-3 sm:p-6 max-w-7xl mx-auto">
      <div className="mb-6 sm:mb-8">
        <h1 className="text-xl sm:text-2xl font-bold text-tx-primary mb-1 sm:mb-2">
          Central de Relatórios
        </h1>
        <p className="text-xs sm:text-sm text-tx-muted mt-1">
          Gere e baixe relatórios personalizados
        </p>
      </div>

      {/* Report Types Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-6 mb-6 sm:mb-8">
        {reportTypes.map((type) => (
          <div
            key={type.id}
            className="bg-surface-card rounded-lg shadow p-4 sm:p-6 hover:shadow-lg transition-shadow"
          >
            <FileText className="w-8 h-8 text-accent-primary mb-3" />
            <h3 className="text-lg font-semibold text-tx-primary mb-2">
              {type.name}
            </h3>
            <p className="text-sm text-tx-secondary mb-4">{type.description}</p>
            <button
              onClick={() => generateReport(type.id)}
              disabled={generating}
              className="w-full px-4 py-2 bg-accent-primary text-white rounded-element hover:bg-accent-hover disabled:opacity-50 transition-colors"
            >
              {generating ? 'Gerando...' : 'Gerar Relatório'}
            </button>
          </div>
        ))}
      </div>

      {/* Recent Reports */}
      <div className="bg-surface-card rounded-lg shadow">
        <div className="p-4 sm:p-6 border-b border-border-DEFAULT">
          <h2 className="text-lg sm:text-xl font-semibold text-tx-primary">
            Relatórios Recentes
          </h2>
        </div>
        <div className="divide-y divide-border-DEFAULT">
          {reports.length === 0 ? (
            <div className="p-12 text-center text-tx-muted">
              <FileText className="w-12 h-12 mx-auto mb-3 opacity-50" />
              <p>Nenhum relatório gerado ainda</p>
            </div>
          ) : (
            reports.map((report) => (
              <div key={report.id} className="p-4 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                  <h3 className="font-medium text-tx-primary">{report.name}</h3>
                  <p className="text-sm text-tx-muted">
                    Gerado em {new Date(report.created_at).toLocaleString('pt-BR')}
                  </p>
                </div>
                <div className="flex items-center gap-3">
                  <span
                    className={`px-3 py-1 rounded-full text-sm font-medium ${
                      report.status === 'completed'
                        ? 'bg-status-success-subtle text-status-success'
                        : report.status === 'failed'
                        ? 'bg-status-error-subtle text-status-error'
                        : 'bg-status-warning-subtle text-status-warning'
                    }`}
                  >
                    {report.status}
                  </span>
                  {report.status === 'completed' && report.file_url && (
                    <a
                      href={safeHref(report.file_url)}
                      download
                      className="flex items-center gap-2 px-4 py-2 bg-accent-primary text-white rounded-element hover:bg-accent-hover"
                    >
                      <Download className="w-4 h-4" />
                      Baixar
                    </a>
                  )}
                </div>
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  );
}
