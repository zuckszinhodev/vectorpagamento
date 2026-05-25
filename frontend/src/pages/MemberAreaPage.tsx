import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Dialog, Transition } from '@headlessui/react';
import { Fragment } from 'react';
import { 
 PlusIcon, 
 AcademicCapIcon, 
 UsersIcon, 
 ChartBarIcon,
 Cog6ToothIcon,
 PlayCircleIcon,
 PencilIcon,
 TrashIcon,
 DocumentDuplicateIcon,
 GlobeAltIcon,
 CheckCircleIcon,
 ArrowPathIcon,
 LinkIcon,
 ClipboardDocumentIcon,
 EyeIcon,
 XMarkIcon,
 UserPlusIcon,
} from '@heroicons/react/24/outline';
import { memberAreaService, Course } from '@/services/api/memberArea.service';
import { domainsService, CustomDomain } from '@/services/api/domains.service';
import toast from 'react-hot-toast';

export default function MemberAreaPage() {
 const queryClient = useQueryClient();
 const [activeTab, setActiveTab] = useState<'dashboard' | 'courses' | 'students' | 'settings'>('dashboard');

 // Fetch dashboard data
 const { data: dashboardData } = useQuery({
 queryKey: ['member-area-dashboard'],
 queryFn: () => memberAreaService.getDashboard(),
 });

 // Fetch courses
 const { data: coursesData, isLoading: coursesLoading } = useQuery({
 queryKey: ['member-area-courses'],
 queryFn: () => memberAreaService.listCourses(),
 });

 // Fetch settings
 const { data: settingsData } = useQuery({
 queryKey: ['member-area-settings'],
 queryFn: () => memberAreaService.getSettings(),
 });

 // Delete course mutation
 const deleteMutation = useMutation({
 mutationFn: (courseId: string) => memberAreaService.deleteCourse(courseId),
 onSuccess: () => {
 queryClient.invalidateQueries({ queryKey: ['member-area-courses'] });
 queryClient.invalidateQueries({ queryKey: ['member-area-dashboard'] });
 toast.success('Curso excluído com sucesso');
 },
 onError: (error: any) => {
 toast.error(error.response?.data?.message || 'Erro ao excluir curso');
 },
 });

 // Duplicate course mutation
 const duplicateMutation = useMutation({
 mutationFn: (courseId: string) => memberAreaService.duplicateCourse(courseId),
 onSuccess: () => {
 queryClient.invalidateQueries({ queryKey: ['member-area-courses'] });
 toast.success('Curso duplicado com sucesso');
 },
 onError: () => {
 toast.error('Erro ao duplicar curso');
 },
 });

 const dashboard = dashboardData?.data;
 const courses = coursesData?.data || [];
 const settings = settingsData?.data;

 // Fetch verified domains to resolve the member area URL
 const { data: domainsData } = useQuery({
 queryKey: ['domains'],
 queryFn: () => domainsService.list(),
 });

 const verifiedDomains = (domainsData?.data || []).filter(
 (d: CustomDomain) => d.status === 'verified'
 );

 const tabs = [
 { id: 'dashboard', name: 'Dashboard', icon: ChartBarIcon },
 { id: 'courses', name: 'Cursos', icon: AcademicCapIcon },
 { id: 'students', name: 'Alunos', icon: UsersIcon },
 { id: 'settings', name: 'Configurações', icon: Cog6ToothIcon },
 ];

 // Build public member area URL from settings or primary verified domain
 const memberAreaUrl = (() => {
   if (settings?.custom_domain && settings?.domain_verified) {
     return `https://${settings.custom_domain}`;
   }
   // Fallback: use primary verified domain, or first verified domain
   const primaryDomain = verifiedDomains.find((d: CustomDomain) => d.is_primary) || verifiedDomains[0];
   if (primaryDomain) {
     return `https://${primaryDomain.domain}`;
   }
   return null;
 })();

 const handleCopyLink = () => {
   if (!memberAreaUrl) return;
   navigator.clipboard.writeText(memberAreaUrl).then(() => {
     toast.success('Link copiado para a área de transferência!');
   }).catch(() => {
     toast.error('Não foi possível copiar o link');
   });
 };

 return (
 <div className="space-y-4 sm:space-y-6">
 {/* Header */}
 <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
 <div>
 <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">Área de Membros</h1>
 <p className="mt-1 text-sm text-tx-muted">
 Gerencie seus cursos, alunos e personalize sua área de membros
 </p>
 </div>
 <div className="flex items-center gap-2 sm:gap-3 flex-wrap">
 {memberAreaUrl && (
 <>
 <button
 onClick={handleCopyLink}
 className="flex items-center gap-2 px-3 sm:px-4 py-2 text-sm font-medium text-tx-secondary bg-surface-card border border-border-DEFAULT rounded-element hover:bg-surface-hover"
 title={memberAreaUrl}
 >
 <ClipboardDocumentIcon className="h-5 w-5" />
 <span className="hidden sm:inline">Copiar Link</span>
 </button>
 <a
 href={memberAreaUrl}
 target="_blank"
 rel="noopener noreferrer"
 className="flex items-center gap-2 px-3 sm:px-4 py-2 text-sm font-medium text-tx-secondary bg-surface-card border border-border-DEFAULT rounded-element hover:bg-surface-hover"
 >
 <EyeIcon className="h-5 w-5" />
 <span className="hidden sm:inline">Visualizar</span>
 </a>
 </>
 )}
 <Link
 to="/member-area/courses/new"
 className="flex items-center gap-2 px-3 sm:px-4 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover"
 >
 <PlusIcon className="h-5 w-5" />
 Novo Curso
 </Link>
 </div>
 </div>

 {/* Public Link Banner */}
 {memberAreaUrl && (
 <div className="bg-surface-card border border-border-DEFAULT rounded-card p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
 <div className="flex items-center gap-3 min-w-0">
 <div className="p-2 bg-accent-primary/10 rounded-lg shrink-0">
 <LinkIcon className="h-5 w-5 text-accent-primary" />
 </div>
 <div className="min-w-0">
 <p className="text-sm font-medium text-tx-primary">Link da Área de Membros</p>
 <p className="text-sm text-accent-primary font-mono truncate">{memberAreaUrl}</p>
 </div>
 </div>
 <div className="flex items-center gap-2 shrink-0">
 <button
 onClick={handleCopyLink}
 className="p-2 text-tx-muted hover:text-tx-primary hover:bg-surface-hover rounded-lg transition-colors"
 title="Copiar link"
 >
 <ClipboardDocumentIcon className="h-5 w-5" />
 </button>
 <a
 href={memberAreaUrl}
 target="_blank"
 rel="noopener noreferrer"
 className="p-2 text-tx-muted hover:text-tx-primary hover:bg-surface-hover rounded-lg transition-colors"
 title="Abrir área de membros"
 >
 <EyeIcon className="h-5 w-5" />
 </a>
 </div>
 </div>
 )}

 {/* Tabs */}
 <div className="border-b border-border-DEFAULT">
 <nav className="-mb-px flex space-x-4 sm:space-x-8">
 {tabs.map((tab) => (
 <button
 key={tab.id}
 onClick={() => setActiveTab(tab.id as any)}
 className={`flex items-center gap-2 py-4 px-1 border-b-2 font-medium text-sm whitespace-nowrap ${
 activeTab === tab.id
 ? 'border-accent-primary text-accent-primary'
 : 'border-transparent text-tx-muted hover:text-tx-secondary hover:border-border-DEFAULT'
 }`}
 >
 <tab.icon className="h-5 w-5" />
 {tab.name}
 </button>
 ))}
 </nav>
 </div>

 {/* Dashboard Tab */}
 {activeTab === 'dashboard' && (
 <>
 {(!dashboard?.total_courses || dashboard.total_courses === 0) ? (
 /* ── Empty state: no courses yet ── */
 <div className="flex flex-col items-center justify-center py-24 px-6">
 <div className="relative mb-8">
 {/* Decorative rings */}
 <div className="absolute inset-0 -m-6 rounded-full border border-accent-primary/10 animate-pulse" />
 <div className="absolute inset-0 -m-12 rounded-full border border-accent-primary/5" />
 <AcademicCapIcon className="h-20 w-20 text-accent-primary" />
 </div>
 <h2 className="text-xl font-bold text-tx-primary text-center">
 Comece a construir sua área de membros
 </h2>
 <p className="mt-3 text-sm text-tx-muted text-center max-w-md leading-relaxed">
 Crie seu primeiro curso, adicione aulas e comece a monetizar seu conhecimento.
 Seus alunos terão acesso a uma plataforma completa e profissional.
 </p>
 <Link
 to="/member-area/courses/new"
 className="mt-8 inline-flex items-center gap-2 px-6 py-3 text-sm font-semibold text-white bg-accent-primary rounded-xl hover:bg-accent-hover transition-all shadow-lg shadow-accent-primary/20 hover:shadow-accent-primary/30"
 >
 <PlusIcon className="h-5 w-5" />
 Criar Primeiro Curso
 </Link>
 </div>
 ) : (
 <div className="space-y-6">
 {/* Stats Grid */}
 <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
 <StatCard
 title="Total de Cursos"
 value={dashboard?.total_courses || 0}
 icon={AcademicCapIcon}
 color="purple"
 />
 <StatCard
 title="Total de Aulas"
 value={dashboard?.total_lessons || 0}
 icon={PlayCircleIcon}
 color="blue"
 />
 <StatCard
 title="Alunos Ativos"
 value={dashboard?.active_enrollments || 0}
 icon={UsersIcon}
 color="green"
 />
 <StatCard
 title="Taxa de Conclusão"
 value={`${dashboard?.completion_rate || 0}%`}
 icon={ChartBarIcon}
 color="yellow"
 />
 </div>

 {/* Recent Enrollments & Popular Courses */}
 <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
 {/* Recent Enrollments */}
 <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
 <h3 className="text-lg font-semibold text-tx-primary mb-4">Matrículas Recentes</h3>
 <div className="space-y-4">
 {dashboard?.recent_enrollments?.slice(0, 5).map((enrollment: any) => (
 <div key={enrollment.id} className="flex items-center justify-between">
 <div className="flex items-center gap-3">
 <div className="h-10 w-10 rounded-full bg-accent-subtle flex items-center justify-center">
 <span className="text-accent-primary font-medium">
 {enrollment.user?.name?.charAt(0) || '?'}
 </span>
 </div>
 <div>
 <p className="text-sm font-medium text-tx-primary">{enrollment.user?.name}</p>
 <p className="text-xs text-tx-muted">{enrollment.course?.name}</p>
 </div>
 </div>
 <span className="text-xs text-tx-muted">
 {new Date(enrollment.created_at).toLocaleDateString('pt-BR')}
 </span>
 </div>
 ))}
 {(!dashboard?.recent_enrollments || dashboard.recent_enrollments.length === 0) && (
 <p className="text-sm text-tx-muted text-center py-4">
 Nenhuma matrícula recente
 </p>
 )}
 </div>
 </div>

 {/* Popular Courses */}
 <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
 <h3 className="text-lg font-semibold text-tx-primary mb-4">Cursos Populares</h3>
 <div className="space-y-4">
 {dashboard?.popular_courses?.map((course: any, index: number) => (
 <div key={course.id} className="flex items-center gap-4">
 <span className="text-lg font-bold text-tx-muted">#{index + 1}</span>
 <div className="h-12 w-20 rounded-lg bg-surface-hover overflow-hidden">
 {course.thumbnail_url ? (
 <img src={course.thumbnail_url} alt={course.name} className="h-full w-full object-cover" />
 ) : (
 <div className="h-full w-full flex items-center justify-center">
 <AcademicCapIcon className="h-6 w-6 text-tx-muted" />
 </div>
 )}
 </div>
 <div className="flex-1">
 <p className="text-sm font-medium text-tx-primary">{course.name}</p>
 <p className="text-xs text-tx-muted">
 {course.enrollments_count || 0} alunos
 </p>
 </div>
 </div>
 ))}
 {(!dashboard?.popular_courses || dashboard.popular_courses.length === 0) && (
 <p className="text-sm text-tx-muted text-center py-4">
 Nenhum curso criado ainda
 </p>
 )}
 </div>
 </div>
 </div>
 </div>
 )}
 </>
 )}

 {/* Courses Tab */}
 {activeTab === 'courses' && (
 <div className="space-y-4">
 {coursesLoading ? (
 <div className="flex justify-center py-12">
 <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-accent-primary"></div>
 </div>
 ) : courses.length === 0 ? (
 <div className="text-center py-12 bg-surface-card rounded-card border border-border-DEFAULT">
 <AcademicCapIcon className="mx-auto h-12 w-12 text-tx-muted" />
 <h3 className="mt-4 text-lg font-medium text-tx-primary">Nenhum curso criado</h3>
 <p className="mt-2 text-sm text-tx-muted">
 Comece criando seu primeiro curso para sua área de membros
 </p>
 <Link
 to="/member-area/courses/new"
 className="mt-4 inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover"
 >
 <PlusIcon className="h-5 w-5" />
 Criar Curso
 </Link>
 </div>
 ) : (
 <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
 {courses.map((course: Course) => (
 <CourseCard
 key={course.id}
 course={course}
 onDelete={() => {
 if (confirm('Tem certeza que deseja excluir este curso?')) {
 deleteMutation.mutate(course.id);
 }
 }}
 onDuplicate={() => duplicateMutation.mutate(course.id)}
 />
 ))}
 </div>
 )}
 </div>
 )}

 {/* Students Tab */}
 {activeTab === 'students' && (
 <StudentsTab />
 )}

 {/* Settings Tab */}
 {activeTab === 'settings' && settings && (
 <SettingsTab settings={settings} />
 )}
 </div>
 );
}

// Stat Card Component
function StatCard({ title, value, icon: Icon, color }: { title: string; value: string | number; icon: any; color: string }) {
 const colorClasses = {
 purple: 'bg-accent-subtle text-accent-primary',
 blue: 'bg-accent-subtle text-accent-primary',
 green: 'bg-status-success-subtle text-status-success',
 yellow: 'bg-status-warning-subtle text-status-warning',
 };

 return (
 <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
 <div className="flex items-center gap-4">
 <div className={`rounded-lg p-3 ${colorClasses[color as keyof typeof colorClasses]}`}>
 <Icon className="h-6 w-6" />
 </div>
 <div>
 <p className="text-sm text-tx-muted">{title}</p>
 <p className="text-xl sm:text-2xl font-bold text-tx-primary">{value}</p>
 </div>
 </div>
 </div>
 );
}

// Course Card Component
function CourseCard({ course, onDelete, onDuplicate }: { course: Course; onDelete: () => void; onDuplicate: () => void }) {
 return (
 <div className="bg-surface-card rounded-card border border-border-DEFAULT overflow-hidden group">
 <div className="aspect-video bg-surface-hover relative">
 {course.thumbnail_url ? (
 <img src={course.thumbnail_url} alt={course.name} className="h-full w-full object-cover" />
 ) : (
 <div className="h-full w-full flex items-center justify-center">
 <AcademicCapIcon className="h-12 w-12 text-tx-muted" />
 </div>
 )}
 <div className="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
 <Link
 to={`/member-area/courses/${course.id}`}
 className="p-2 bg-surface-card/20 rounded-element hover:bg-surface-card/30 transition-colors"
 title="Editar"
 >
 <PencilIcon className="h-5 w-5 text-white" />
 </Link>
 <button
 onClick={onDuplicate}
 className="p-2 bg-surface-card/20 rounded-element hover:bg-surface-card/30 transition-colors"
 title="Duplicar"
 >
 <DocumentDuplicateIcon className="h-5 w-5 text-white" />
 </button>
 <button
 onClick={onDelete}
 className="p-2 bg-surface-card/20 rounded-element hover:bg-status-error-subtle0/50 transition-colors"
 title="Excluir"
 >
 <TrashIcon className="h-5 w-5 text-white" />
 </button>
 </div>
 {!course.is_published && (
 <span className="absolute top-2 left-2 px-2 py-1 text-xs font-medium bg-status-warning-subtle0 text-white rounded">
 Rascunho
 </span>
 )}
 </div>
 <div className="p-4">
 <h3 className="font-semibold text-tx-primary line-clamp-1">{course.name}</h3>
 <p className="mt-1 text-sm text-tx-muted line-clamp-2">
 {course.short_description || course.description || 'Sem descrição'}
 </p>
 <div className="mt-3 flex items-center justify-between text-sm text-tx-muted">
 <span>{course.modules_count || 0} módulos</span>
 <span>{course.enrollments_count || 0} alunos</span>
 </div>
 </div>
 </div>
 );
}

// Students Tab Component
function StudentsTab() {
 const queryClient = useQueryClient();
 const [isModalOpen, setIsModalOpen] = useState(false);
 const [formData, setFormData] = useState({
   course_id: '',
   user_email: '',
   user_name: '',
   access_type: 'lifetime',
 });

 const { data, isLoading, error } = useQuery({
   queryKey: ['member-area-enrollments'],
   queryFn: () => memberAreaService.listEnrollments(),
   retry: false,
 });

 const { data: coursesData } = useQuery({
   queryKey: ['member-area-courses'],
   queryFn: () => memberAreaService.listCourses(),
 });

 const courses = coursesData?.data || [];
 const enrollments = data?.data || [];

 const createMutation = useMutation({
   mutationFn: (data: { course_id: string; user_email: string; user_name?: string; access_type?: string }) =>
     memberAreaService.createEnrollment(data),
   onSuccess: () => {
     queryClient.invalidateQueries({ queryKey: ['member-area-enrollments'] });
     queryClient.invalidateQueries({ queryKey: ['member-area-dashboard'] });
     toast.success('Aluno matriculado com sucesso! Um e-mail com as credenciais foi enviado.');
     setIsModalOpen(false);
     setFormData({ course_id: '', user_email: '', user_name: '', access_type: 'lifetime' });
   },
   onError: (error: any) => {
     // Handle Laravel validation errors
     const errors = error.response?.data?.errors;
     if (errors) {
       const firstError = Object.values(errors)[0];
       toast.error(Array.isArray(firstError) ? firstError[0] : String(firstError));
       return;
     }
     toast.error(error.response?.data?.message || 'Erro ao matricular aluno');
   },
 });

 const handleSubmit = (e: React.FormEvent) => {
   e.preventDefault();
   if (!formData.course_id || !formData.user_email) {
     toast.error('Selecione um curso e informe o e-mail');
     return;
   }
   createMutation.mutate(formData);
 };

 if (isLoading) {
   return (
     <div className="flex justify-center py-12">
       <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-accent-primary"></div>
     </div>
   );
 }

 if (error) {
   return (
     <div className="flex flex-col items-center justify-center py-12 text-center">
       <p className="text-status-error mb-4">Erro ao carregar alunos</p>
       <p className="text-tx-muted text-sm">{(error as any)?.response?.data?.message || (error as Error).message}</p>
     </div>
   );
 }

 return (
   <>
     {/* Header com botão */}
     <div className="flex items-center justify-between mb-4">
       <div />
       <button
         onClick={() => setIsModalOpen(true)}
         className="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover"
       >
         <UserPlusIcon className="h-5 w-5" />
         Cadastrar Aluno
       </button>
     </div>

     {/* Tabela */}
     <div className="bg-surface-card rounded-card border border-border-DEFAULT">
       <div className="overflow-x-auto">
         <table className="w-full">
           <thead>
             <tr className="border-b border-border-DEFAULT">
               <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-tx-muted uppercase tracking-wider">Aluno</th>
               <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-tx-muted uppercase tracking-wider">Curso</th>
               <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-tx-muted uppercase tracking-wider">Progresso</th>
               <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-tx-muted uppercase tracking-wider">Status</th>
               <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-tx-muted uppercase tracking-wider">Matrícula</th>
             </tr>
           </thead>
           <tbody className="divide-y divide-border-DEFAULT">
             {enrollments.map((enrollment: any) => (
               <tr key={enrollment.id}>
                 <td className="px-3 sm:px-6 py-4 whitespace-nowrap">
                   <div className="flex items-center gap-3">
                     <div className="h-8 w-8 rounded-full bg-accent-subtle flex items-center justify-center">
                       <span className="text-accent-primary text-sm font-medium">
                         {enrollment.user?.name?.charAt(0) || '?'}
                       </span>
                     </div>
                     <div>
                       <p className="text-sm font-medium text-tx-primary">{enrollment.user?.name}</p>
                       <p className="text-xs text-tx-muted">{enrollment.user?.email}</p>
                     </div>
                   </div>
                 </td>
                 <td className="px-3 sm:px-6 py-4 whitespace-nowrap">
                   <p className="text-sm text-tx-primary">{enrollment.course?.name}</p>
                 </td>
                 <td className="px-3 sm:px-6 py-4 whitespace-nowrap">
                   <div className="flex items-center gap-2">
                     <div className="h-2 w-24 bg-surface-hover rounded-full overflow-hidden">
                       <div
                         className="h-full bg-accent-primary rounded-full"
                         style={{ width: `${enrollment.progress_percent || 0}%` }}
                       />
                     </div>
                     <span className="text-sm text-tx-muted">{enrollment.progress_percent || 0}%</span>
                   </div>
                 </td>
                 <td className="px-3 sm:px-6 py-4 whitespace-nowrap">
                   <span className={`px-2 py-1 text-xs font-medium rounded ${
                     enrollment.status === 'active' 
                       ? 'bg-status-success-subtle text-green-800'
                       : enrollment.status === 'expired'
                       ? 'bg-status-error-subtle text-red-800'
                       : 'bg-surface-hover text-tx-muted'
                   }`}>
                     {enrollment.status === 'active' ? 'Ativo' : enrollment.status === 'expired' ? 'Expirado' : enrollment.status}
                   </span>
                 </td>
                 <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-tx-muted">
                   {enrollment.enrolled_at ? new Date(enrollment.enrolled_at).toLocaleDateString('pt-BR') : '-'}
                 </td>
               </tr>
             ))}
             {enrollments.length === 0 && (
               <tr>
                 <td colSpan={5} className="px-3 sm:px-6 py-12 text-center text-tx-muted">
                   Nenhum aluno matriculado ainda
                 </td>
               </tr>
             )}
           </tbody>
         </table>
       </div>
     </div>

     {/* Modal de Cadastro */}
     <Transition appear show={isModalOpen} as={Fragment}>
       <Dialog as="div" className="relative z-50" onClose={() => setIsModalOpen(false)}>
         <Transition.Child
           as={Fragment}
           enter="ease-out duration-300"
           enterFrom="opacity-0"
           enterTo="opacity-100"
           leave="ease-in duration-200"
           leaveFrom="opacity-100"
           leaveTo="opacity-0"
         >
           <div className="fixed inset-0 bg-black/50" />
         </Transition.Child>

         <div className="fixed inset-0 overflow-y-auto">
           <div className="flex min-h-full items-center justify-center p-4">
             <Transition.Child
               as={Fragment}
               enter="ease-out duration-300"
               enterFrom="opacity-0 scale-95"
               enterTo="opacity-100 scale-100"
               leave="ease-in duration-200"
               leaveFrom="opacity-100 scale-100"
               leaveTo="opacity-0 scale-95"
             >
               <Dialog.Panel className="w-full max-w-md transform rounded-card bg-surface-card border border-border-DEFAULT p-4 sm:p-6 shadow-xl transition-all">
                 <div className="flex items-center justify-between mb-4">
                   <Dialog.Title className="text-lg font-semibold text-tx-primary">
                     Cadastrar Aluno
                   </Dialog.Title>
                   <button
                     onClick={() => setIsModalOpen(false)}
                     className="text-tx-muted hover:text-tx-primary"
                   >
                     <XMarkIcon className="h-5 w-5" />
                   </button>
                 </div>

                 <p className="text-sm text-tx-muted mb-4">
                   O aluno receberá um e-mail com as credenciais de acesso.
                 </p>

                 <form onSubmit={handleSubmit} className="space-y-4">
                   <div>
                     <label className="block text-sm font-medium text-tx-secondary mb-1">
                       Curso *
                     </label>
                     <select
                       value={formData.course_id}
                       onChange={(e) => setFormData({ ...formData, course_id: e.target.value })}
                       className="w-full px-3 py-2 bg-surface-input border border-border-DEFAULT rounded-element text-tx-primary focus:outline-none focus:border-accent-primary"
                       required
                     >
                       <option value="">Selecione um curso</option>
                       {courses.map((course: Course) => (
                         <option key={course.id} value={course.id}>
                           {course.name}
                         </option>
                       ))}
                     </select>
                   </div>

                   <div>
                     <label className="block text-sm font-medium text-tx-secondary mb-1">
                       E-mail do Aluno *
                     </label>
                     <input
                       type="email"
                       value={formData.user_email}
                       onChange={(e) => setFormData({ ...formData, user_email: e.target.value })}
                       className="w-full px-3 py-2 bg-surface-input border border-border-DEFAULT rounded-element text-tx-primary focus:outline-none focus:border-accent-primary"
                       placeholder="aluno@email.com"
                       required
                     />
                   </div>

                   <div>
                     <label className="block text-sm font-medium text-tx-secondary mb-1">
                       Nome do Aluno
                     </label>
                     <input
                       type="text"
                       value={formData.user_name}
                       onChange={(e) => setFormData({ ...formData, user_name: e.target.value })}
                       className="w-full px-3 py-2 bg-surface-input border border-border-DEFAULT rounded-element text-tx-primary focus:outline-none focus:border-accent-primary"
                       placeholder="Nome completo (opcional)"
                     />
                   </div>

                   <div>
                     <label className="block text-sm font-medium text-tx-secondary mb-1">
                       Tipo de Acesso
                     </label>
                     <select
                       value={formData.access_type}
                       onChange={(e) => setFormData({ ...formData, access_type: e.target.value })}
                       className="w-full px-3 py-2 bg-surface-input border border-border-DEFAULT rounded-element text-tx-primary focus:outline-none focus:border-accent-primary"
                     >
                       <option value="lifetime">Vitalício</option>
                       <option value="subscription">Assinatura</option>
                       <option value="limited">Limitado</option>
                     </select>
                   </div>

                   <div className="flex gap-3 pt-2">
                     <button
                       type="button"
                       onClick={() => setIsModalOpen(false)}
                       className="flex-1 px-4 py-2 text-sm font-medium text-tx-secondary bg-surface-hover rounded-element hover:bg-surface-card"
                     >
                       Cancelar
                     </button>
                     <button
                       type="submit"
                       disabled={createMutation.isPending}
                       className="flex-1 px-4 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover disabled:opacity-50"
                     >
                       {createMutation.isPending ? 'Cadastrando...' : 'Cadastrar'}
                     </button>
                   </div>
                 </form>
               </Dialog.Panel>
             </Transition.Child>
           </div>
         </div>
       </Dialog>
     </Transition>
   </>
 );
}

// Settings Tab Component
function SettingsTab({ settings }: { settings: any }) {
 const queryClient = useQueryClient();
 const [formData, setFormData] = useState(settings);

 const mutation = useMutation({
 mutationFn: (data: any) => memberAreaService.updateSettings(data),
 onSuccess: () => {
 queryClient.invalidateQueries({ queryKey: ['member-area-settings'] });
 toast.success('Configurações salvas com sucesso');
 },
 onError: () => {
 toast.error('Erro ao salvar configurações');
 },
 });

 const handleSubmit = (e: React.FormEvent) => {
 e.preventDefault();
 mutation.mutate(formData);
 };

 return (
 <form onSubmit={handleSubmit} className="space-y-6">
 {/* Branding */}
 <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
 <h3 className="text-lg font-semibold text-tx-primary mb-4">Identidade Visual</h3>
 <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-1">Nome da Área</label>
 <input
 type="text"
 value={formData.name || ''}
 onChange={(e) => setFormData({ ...formData, name: e.target.value })}
 className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
 />
 </div>
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-1">URL do Logo</label>
 <input
 type="url"
 value={formData.logo_url || ''}
 onChange={(e) => setFormData({ ...formData, logo_url: e.target.value })}
 className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
 />
 </div>
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-1">Cor Primária</label>
 <div className="flex gap-2">
 <input
 type="color"
 value={formData.primary_color || '#8B5CF6'}
 onChange={(e) => setFormData({ ...formData, primary_color: e.target.value })}
 className="h-10 w-14 border border-border-DEFAULT rounded cursor-pointer"
 />
 <input
 type="text"
 value={formData.primary_color || '#8B5CF6'}
 onChange={(e) => setFormData({ ...formData, primary_color: e.target.value })}
 className="flex-1 px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
 />
 </div>
 </div>
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-1">Cor Secundária</label>
 <div className="flex gap-2">
 <input
 type="color"
 value={formData.secondary_color || '#EC4899'}
 onChange={(e) => setFormData({ ...formData, secondary_color: e.target.value })}
 className="h-10 w-14 border border-border-DEFAULT rounded cursor-pointer"
 />
 <input
 type="text"
 value={formData.secondary_color || '#EC4899'}
 onChange={(e) => setFormData({ ...formData, secondary_color: e.target.value })}
 className="flex-1 px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
 />
 </div>
 </div>
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-1">Tema</label>
 <select
 value={formData.theme_mode || 'dark'}
 onChange={(e) => setFormData({ ...formData, theme_mode: e.target.value })}
 className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
 >
 <option value="dark">Escuro</option>
 <option value="light">Claro</option>
 </select>
 </div>
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-1">Layout</label>
 <select
 value={formData.layout_style || 'netflix'}
 onChange={(e) => setFormData({ ...formData, layout_style: e.target.value })}
 className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
 >
 <option value="netflix">Streaming (Netflix)</option>
 <option value="traditional">Tradicional</option>
 <option value="minimal">Minimalista</option>
 <option value="grid">Grade</option>
 <option value="list">Lista</option>
 </select>
 </div>
 </div>
 </div>

 {/* Domain */}
 <DomainSection formData={formData} setFormData={setFormData} />

 {/* Welcome */}
 <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
 <h3 className="text-lg font-semibold text-tx-primary mb-4">Página Inicial</h3>
 <div className="space-y-4">
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-1">Mensagem de Boas-vindas</label>
 <textarea
 value={formData.welcome_message || ''}
 onChange={(e) => setFormData({ ...formData, welcome_message: e.target.value })}
 rows={3}
 className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
 placeholder="Bem-vindo à nossa área de membros!"
 />
 </div>
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-1">URL do Banner</label>
 <input
 type="url"
 value={formData.banner_url || ''}
 onChange={(e) => setFormData({ ...formData, banner_url: e.target.value })}
 className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
 />
 </div>
 </div>
 </div>

 <div className="flex justify-end">
 <button
 type="submit"
 disabled={mutation.isPending}
 className="px-6 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover disabled:opacity-50"
 >
 {mutation.isPending ? 'Salvando...' : 'Salvar Configurações'}
 </button>
 </div>
 </form>
 );
}
// Domain Section Component — only verified domains from /domains
function DomainSection({ formData, setFormData }: { formData: any; setFormData: (data: any) => void }) {
 const [isChanging, setIsChanging] = useState(false);

 // Fetch verified domains from the domains page
 const { data: domainsData, isLoading: domainsLoading } = useQuery({
 queryKey: ['domains'],
 queryFn: () => domainsService.list(),
 });

 const verifiedDomains = (domainsData?.data || []).filter(
 (d: CustomDomain) => d.status === 'verified'
 );

 const handleSelectVerifiedDomain = (domain: string) => {
 setFormData({ ...formData, custom_domain: domain, domain_verified: true });
 setIsChanging(false);
 };

 const handleRemoveDomain = () => {
 setFormData({ ...formData, custom_domain: '', domain_verified: false });
 setIsChanging(false);
 };

 const selectedDomainObj = verifiedDomains.find((d: CustomDomain) => d.domain === formData.custom_domain);
 const hasDomainSelected = formData.custom_domain && formData.domain_verified;

 return (
 <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6 space-y-6">
 <div className="flex items-center justify-between">
 <div>
 <h3 className="text-lg font-semibold text-tx-primary">Domínio da Área de Membros</h3>
 <p className="text-sm text-tx-muted">
 Selecione um domínio verificado para seus alunos acessarem a área de membros
 </p>
 </div>
 {hasDomainSelected && !isChanging && (
 <span className="flex items-center gap-1.5 px-3 py-1 bg-status-success-subtle text-status-success rounded-full text-sm font-medium">
 <CheckCircleIcon className="h-4 w-4" />
 Configurado
 </span>
 )}
 </div>

 {domainsLoading ? (
 <div className="flex items-center gap-2 py-3 text-sm text-tx-muted">
 <ArrowPathIcon className="h-4 w-4 animate-spin" />
 Carregando domínios...
 </div>
 ) : hasDomainSelected && !isChanging ? (
 /* Domínio selecionado — exibição com ações */
 <div className="space-y-4">
 <div className="flex items-center gap-3 p-4 rounded-lg border-2 border-accent-primary bg-accent-subtle">
 <GlobeAltIcon className="h-5 w-5 text-accent-primary flex-shrink-0" />
 <div className="flex-1 min-w-0">
 <p className="text-sm font-semibold text-tx-primary truncate">{formData.custom_domain}</p>
 {selectedDomainObj?.is_primary && (
 <span className="text-xs text-accent-primary">Domínio principal</span>
 )}
 </div>
 <span className="flex items-center gap-1 text-xs font-medium text-status-success flex-shrink-0">
 <CheckCircleIcon className="h-4 w-4" />
 Verificado
 </span>
 </div>

 <p className="text-xs text-tx-muted">
 Seus alunos acessarão: <span className="text-accent-primary font-medium">https://{formData.custom_domain}</span>
 </p>

 <div className="flex items-center gap-3">
 <button
 type="button"
 onClick={() => setIsChanging(true)}
 className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-tx-primary bg-surface-page border border-border-DEFAULT rounded-element hover:bg-surface-hover transition-colors"
 >
 <PencilIcon className="h-4 w-4" />
 Alterar Domínio
 </button>
 <button
 type="button"
 onClick={handleRemoveDomain}
 className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-status-error bg-status-error-subtle border border-status-error/20 rounded-element hover:bg-status-error/20 transition-colors"
 >
 <TrashIcon className="h-4 w-4" />
 Remover Domínio
 </button>
 </div>
 </div>
 ) : verifiedDomains.length > 0 ? (
 /* Seletor de domínios */
 <div>
 <label className="block text-sm font-medium text-tx-secondary mb-2">
 Selecionar Domínio Verificado
 </label>
 <div className="space-y-2">
 {verifiedDomains.map((domain: CustomDomain) => (
 <label
 key={domain.id}
 className={`flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition-all ${
 formData.custom_domain === domain.domain
 ? 'border-accent-primary bg-accent-subtle'
 : 'border-border-DEFAULT bg-surface-card hover:border-accent-primary/40'
 }`}
 >
 <input
 type="radio"
 name="custom_domain"
 value={domain.domain}
 checked={formData.custom_domain === domain.domain}
 onChange={() => handleSelectVerifiedDomain(domain.domain)}
 className="sr-only"
 />
 <div className={`flex-shrink-0 w-5 h-5 rounded-full border-2 flex items-center justify-center ${
 formData.custom_domain === domain.domain
 ? 'border-accent-primary'
 : 'border-border-DEFAULT'
 }`}>
 {formData.custom_domain === domain.domain && (
 <div className="w-2.5 h-2.5 rounded-full bg-accent-primary" />
 )}
 </div>
 <div className="flex-1">
 <p className="text-sm font-medium text-tx-primary">{domain.domain}</p>
 {domain.is_primary && (
 <span className="text-xs text-accent-primary">Domínio principal</span>
 )}
 </div>
 <span className="flex items-center gap-1 text-xs font-medium text-status-success">
 <CheckCircleIcon className="h-4 w-4" />
 Verificado
 </span>
 </label>
 ))}
 </div>
 {isChanging && (
 <button
 type="button"
 onClick={() => setIsChanging(false)}
 className="mt-3 text-sm text-tx-muted hover:text-tx-primary transition-colors"
 >
 Cancelar
 </button>
 )}
 </div>
 ) : (
 <div className="bg-surface-page rounded-lg p-5 text-center">
 <GlobeAltIcon className="mx-auto h-10 w-10 text-tx-muted mb-3" />
 <p className="text-sm font-medium text-tx-primary">
 Nenhum domínio verificado encontrado
 </p>
 <p className="text-xs text-tx-muted mt-1 mb-4">
 Adicione e verifique um domínio na página de Domínios com uso para "Área de Membros" antes de usá-lo aqui.
 </p>
 <Link
 to="/domains"
 className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover"
 >
 <GlobeAltIcon className="h-4 w-4" />
 Gerenciar Domínios
 </Link>
 </div>
 )}
 </div>
 );
}