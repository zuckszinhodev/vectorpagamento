import { useState, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  ArrowLeftIcon,
  UserCircleIcon,
  CameraIcon,
  CheckIcon,
  EyeIcon,
  EyeSlashIcon,
} from '@heroicons/react/24/solid';
import { memberAreaService } from '@/services/api/memberArea.service';
import toast from 'react-hot-toast';

interface ProfileData {
  id: string;
  name: string;
  email: string;
  avatar_url?: string;
  phone?: string;
}

export default function MemberProfilePage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [showPassword, setShowPassword] = useState(false);
  const [formData, setFormData] = useState({
    name: '',
    avatar_url: '',
    phone: '',
    password: '',
    password_confirmation: '',
  });

  // Fetch profile
  const { data: profileData, isLoading } = useQuery({
    queryKey: ['member-profile'],
    queryFn: () => memberAreaService.getMemberProfile(),
  });

  const profile: ProfileData | undefined = profileData?.data;

  // Update form when profile data is loaded
  useEffect(() => {
    if (profile) {
      setFormData((prev) => ({
        ...prev,
        name: profile.name || '',
        avatar_url: profile.avatar_url || '',
        phone: profile.phone || '',
      }));
    }
  }, [profile]);

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: (data: { name?: string; avatar_url?: string; phone?: string; password?: string; password_confirmation?: string }) =>
      memberAreaService.updateMemberProfile(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['member-profile'] });
      toast.success('Perfil atualizado com sucesso!');
      // Clear password fields
      setFormData((prev) => ({ ...prev, password: '', password_confirmation: '' }));
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message || 'Erro ao atualizar perfil');
    },
  });

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    // Build update payload
    const payload: Record<string, string> = {};
    if (formData.name !== profile?.name) payload.name = formData.name;
    if (formData.avatar_url !== profile?.avatar_url) payload.avatar_url = formData.avatar_url;
    if (formData.phone !== profile?.phone) payload.phone = formData.phone;
    if (formData.password) {
      if (formData.password !== formData.password_confirmation) {
        toast.error('As senhas não coincidem');
        return;
      }
      if (formData.password.length < 8) {
        toast.error('A senha deve ter pelo menos 8 caracteres');
        return;
      }
      payload.password = formData.password;
      payload.password_confirmation = formData.password_confirmation;
    }

    if (Object.keys(payload).length === 0) {
      toast('Nenhuma alteração detectada');
      return;
    }

    updateMutation.mutate(payload);
  };

  if (isLoading) {
    return (
      <div className="min-h-screen bg-bg-primary flex items-center justify-center">
        <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-emerald-500" />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-bg-primary">
      {/* Header */}
      <header className="bg-bg-secondary border-b border-border sticky top-0 z-50">
        <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="h-16 flex items-center gap-4">
            <Link
              to="/members/dashboard"
              className="flex items-center gap-2 text-tx-muted hover:text-white transition-colors"
            >
              <ArrowLeftIcon className="h-5 w-5" />
              <span className="hidden sm:inline">Voltar ao Dashboard</span>
            </Link>
            <h1 className="text-white font-semibold text-lg">Meu Perfil</h1>
          </div>
        </div>
      </header>

      {/* Content */}
      <main className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <form onSubmit={handleSubmit} className="space-y-6">
          {/* Avatar Section */}
          <div className="bg-bg-secondary border border-border rounded-xl p-6">
            <div className="flex items-center gap-6">
              <div className="relative">
                {formData.avatar_url ? (
                  <img
                    src={formData.avatar_url}
                    alt={formData.name}
                    className="h-24 w-24 rounded-full object-cover border-4 border-emerald-500"
                  />
                ) : (
                  <UserCircleIcon className="h-24 w-24 text-tx-muted" />
                )}
                <button
                  type="button"
                  className="absolute bottom-0 right-0 p-2 bg-emerald-600 rounded-full text-white hover:bg-emerald-500 transition-colors"
                >
                  <CameraIcon className="h-4 w-4" />
                </button>
              </div>
              <div className="flex-1">
                <label className="block text-sm font-medium text-tx-muted mb-1">
                  URL do Avatar
                </label>
                <input
                  type="url"
                  name="avatar_url"
                  value={formData.avatar_url}
                  onChange={handleChange}
                  placeholder="https://exemplo.com/avatar.jpg"
                  className="w-full px-4 py-2 bg-bg-primary border border-border rounded-lg text-white placeholder-tx-muted focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
                />
              </div>
            </div>
          </div>

          {/* Personal Info */}
          <div className="bg-bg-secondary border border-border rounded-xl p-6 space-y-4">
            <h2 className="text-white font-semibold text-lg mb-4">Informações Pessoais</h2>

            <div>
              <label className="block text-sm font-medium text-tx-muted mb-1">Nome</label>
              <input
                type="text"
                name="name"
                value={formData.name}
                onChange={handleChange}
                className="w-full px-4 py-2 bg-bg-primary border border-border rounded-lg text-white placeholder-tx-muted focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-tx-muted mb-1">Email</label>
              <input
                type="email"
                value={profile?.email || ''}
                disabled
                className="w-full px-4 py-2 bg-bg-primary border border-border rounded-lg text-tx-muted cursor-not-allowed"
              />
              <p className="mt-1 text-xs text-tx-muted">O email não pode ser alterado.</p>
            </div>

            <div>
              <label className="block text-sm font-medium text-tx-muted mb-1">Telefone</label>
              <input
                type="tel"
                name="phone"
                value={formData.phone}
                onChange={handleChange}
                placeholder="(11) 99999-9999"
                className="w-full px-4 py-2 bg-bg-primary border border-border rounded-lg text-white placeholder-tx-muted focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
              />
            </div>
          </div>

          {/* Password */}
          <div className="bg-bg-secondary border border-border rounded-xl p-6 space-y-4">
            <h2 className="text-white font-semibold text-lg mb-4">Alterar Senha</h2>

            <div className="relative">
              <label className="block text-sm font-medium text-tx-muted mb-1">Nova Senha</label>
              <input
                type={showPassword ? 'text' : 'password'}
                name="password"
                value={formData.password}
                onChange={handleChange}
                placeholder="Digite a nova senha"
                className="w-full px-4 py-2 pr-10 bg-bg-primary border border-border rounded-lg text-white placeholder-tx-muted focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
              />
              <button
                type="button"
                onClick={() => setShowPassword(!showPassword)}
                className="absolute right-3 top-8 text-tx-muted hover:text-white"
              >
                {showPassword ? <EyeSlashIcon className="h-5 w-5" /> : <EyeIcon className="h-5 w-5" />}
              </button>
            </div>

            <div>
              <label className="block text-sm font-medium text-tx-muted mb-1">Confirmar Senha</label>
              <input
                type={showPassword ? 'text' : 'password'}
                name="password_confirmation"
                value={formData.password_confirmation}
                onChange={handleChange}
                placeholder="Confirme a nova senha"
                className="w-full px-4 py-2 bg-bg-primary border border-border rounded-lg text-white placeholder-tx-muted focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent"
              />
            </div>

            <p className="text-xs text-tx-muted">
              Deixe em branco se não deseja alterar a senha. Mínimo de 8 caracteres.
            </p>
          </div>

          {/* Submit */}
          <div className="flex items-center justify-end gap-4">
            <Link
              to="/members/dashboard"
              className="px-6 py-2 border border-border text-tx-muted rounded-lg hover:bg-bg-secondary transition-colors"
            >
              Cancelar
            </Link>
            <button
              type="submit"
              disabled={updateMutation.isPending}
              className="px-6 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-500 transition-colors flex items-center gap-2 disabled:opacity-50"
            >
              <CheckIcon className="h-4 w-4" />
              {updateMutation.isPending ? 'Salvando...' : 'Salvar Alterações'}
            </button>
          </div>
        </form>
      </main>
    </div>
  );
}
