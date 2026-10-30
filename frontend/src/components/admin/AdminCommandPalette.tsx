import { useEffect, useMemo, useRef, useState, type ComponentType } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { MagnifyingGlassIcon, BuildingStorefrontIcon } from '@heroicons/react/24/outline';
import clsx from 'clsx';
import { api } from '@/lib/api';

export interface PaletteItem {
  name: string;
  href: string;
  icon: ComponentType<{ className?: string }>;
  group: string;
}

interface SellerResult {
  id: string;
  name: string;
  email: string;
}

export default function AdminCommandPalette({
  open,
  onClose,
  items,
  canSearchSellers,
}: {
  open: boolean;
  onClose: () => void;
  items: PaletteItem[];
  canSearchSellers: boolean;
}) {
  const navigate = useNavigate();
  const inputRef = useRef<HTMLInputElement>(null);
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [selected, setSelected] = useState(0);
  const term = search.trim().toLowerCase();

  useEffect(() => {
    if (!open) return;
    setSearch('');
    setSelected(0);
    inputRef.current?.focus();
  }, [open]);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), 250);
    return () => clearTimeout(timer);
  }, [search]);

  const { data: sellers = [] } = useQuery<SellerResult[]>({
    queryKey: ['admin-palette-sellers', debounced],
    queryFn: async () => {
      const response = await api.get('/admin/customers', { params: { search: debounced, per_page: 5 } });
      return response.data?.data ?? [];
    },
    enabled: open && canSearchSellers && debounced.length >= 2,
    staleTime: 30000,
  });

  const results = useMemo(() => {
    const pages = items
      .filter((item) => !term || `${item.name} ${item.group}`.toLowerCase().includes(term))
      .map((item) => ({ key: item.href, label: item.name, hint: item.group, href: item.href, icon: item.icon }));
    const sellerResults = (term ? sellers : []).map((seller) => ({
      key: seller.id,
      label: seller.name,
      hint: seller.email,
      href: `/admin/sellers/${seller.id}`,
      icon: BuildingStorefrontIcon,
    }));
    return [...pages, ...sellerResults];
  }, [items, sellers, term]);

  useEffect(() => setSelected(0), [term, sellers]);

  if (!open) return null;

  const go = (href: string) => {
    onClose();
    navigate(href);
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape') onClose();
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setSelected((i) => Math.min(i + 1, results.length - 1));
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      setSelected((i) => Math.max(i - 1, 0));
    }
    if (e.key === 'Enter' && results[selected]) go(results[selected].href);
  };

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-center bg-black/60 p-4 pt-[12vh] backdrop-blur-sm" onClick={onClose}>
      <div
        role="dialog"
        aria-label="Busca do admin"
        className="w-full max-w-xl overflow-hidden rounded-2xl border border-white/10 bg-[#0e0e11]"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center gap-3 border-b border-white/[0.06] px-4">
          <MagnifyingGlassIcon className="h-5 w-5 shrink-0 text-gray-500" />
          <input
            ref={inputRef}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder="Buscar telas e sellers"
            aria-label="Buscar telas e sellers"
            className="h-12 w-full border-0 bg-transparent text-sm text-white placeholder:text-gray-500 focus:outline-none focus:ring-0"
          />
          <kbd className="text-[10px] font-medium text-gray-500">Esc</kbd>
        </div>
        <ul className="max-h-[50vh] overflow-y-auto p-2">
          {results.map((result, index) => (
            <li key={result.key}>
              <button
                type="button"
                onClick={() => go(result.href)}
                onMouseEnter={() => setSelected(index)}
                className={clsx(
                  'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm',
                  index === selected ? 'bg-red-500/10 text-white' : 'text-gray-300'
                )}
              >
                <result.icon className="h-4 w-4 shrink-0 text-red-400" />
                <span className="min-w-0 flex-1 truncate">{result.label}</span>
                <span className="max-w-[45%] truncate text-xs text-gray-500">{result.hint}</span>
              </button>
            </li>
          ))}
          {results.length === 0 && <li className="px-3 py-6 text-center text-sm text-gray-500">Nada encontrado.</li>}
        </ul>
      </div>
    </div>
  );
}
