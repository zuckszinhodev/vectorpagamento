import { useState, useEffect, useMemo } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import {
  ArrowLeftIcon,
  PlusIcon,
  PencilIcon,
  TrashIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  PlayCircleIcon,
  DocumentTextIcon,
  Bars3Icon,
  EyeIcon,
  CheckCircleIcon,
  UsersIcon,
  ChatBubbleLeftRightIcon,
  ChartBarIcon,
  ArrowDownTrayIcon,
  VideoCameraIcon,
  QuestionMarkCircleIcon,
  SignalIcon,
  XMarkIcon,
  ArrowsUpDownIcon,
  TagIcon,
  ClockIcon,
  AcademicCapIcon,
  MagnifyingGlassIcon,
  ClipboardDocumentIcon,
  LinkIcon,
  KeyIcon,
} from '@heroicons/react/24/outline';
import { DragDropContext, Droppable, Draggable } from '@hello-pangea/dnd';
import { memberAreaService, Course, CourseModule, Lesson, Enrollment, LessonComment } from '@/services/api/memberArea.service';
import { domainsService, CustomDomain } from '@/services/api/domains.service';
import toast from 'react-hot-toast';

// ─── Types ───────────────────────────────────────────

interface CourseFormData {
  name: string;
  slug: string;
  description: string;
  short_description: string;
  thumbnail_url: string;
  cover_url: string;
  trailer_url: string;
  instructor_name: string;
  instructor_bio: string;
  level: 'beginner' | 'intermediate' | 'advanced' | 'all';
  is_published: boolean;
  tags: string[];
  requirements: string[];
  objectives: string[];
}

type EditorTab = 'info' | 'content' | 'students' | 'comments' | 'analytics';

const CONTENT_TYPE_CONFIG = {
  video: { icon: PlayCircleIcon, label: 'Vídeo', color: 'text-emerald-400' },
  text: { icon: DocumentTextIcon, label: 'Texto', color: 'text-blue-400' },
  quiz: { icon: QuestionMarkCircleIcon, label: 'Quiz', color: 'text-amber-400' },
  download: { icon: ArrowDownTrayIcon, label: 'Download', color: 'text-emerald-400' },
  live: { icon: SignalIcon, label: 'Ao Vivo', color: 'text-red-400' },
};

// ─── Main Component ──────────────────────────────────

export default function CourseEditorPage() {
  const { courseId } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const isNew = courseId === 'new';

  const [activeTab, setActiveTab] = useState<EditorTab>('info');
  const [expandedModules, setExpandedModules] = useState<string[]>([]);
  const [editingModule, setEditingModule] = useState<string | null>(null);
  const [editingLesson, setEditingLesson] = useState<{ moduleId: string; lessonId: string } | null>(null);
  const [showModuleModal, setShowModuleModal] = useState(false);
  const [showLessonModal, setShowLessonModal] = useState<string | null>(null);

  // Fetch verified domains to build course URL
  const { data: domainsData } = useQuery({
    queryKey: ['domains'],
    queryFn: () => domainsService.list(),
  });
  const memberAreaDomain = (() => {
    const verified = (domainsData?.data || []).filter((d: CustomDomain) => d.status === 'verified');
    const primary = verified.find((d: CustomDomain) => d.is_primary) || verified[0];
    return primary ? `https://${primary.domain}` : null;
  })();

  // Tag / requirement / objective input states
  const [tagInput, setTagInput] = useState('');
  const [reqInput, setReqInput] = useState('');
  const [objInput, setObjInput] = useState('');

  // Fetch course data
  const { data: courseData, isLoading } = useQuery({
    queryKey: ['member-area-course', courseId],
    queryFn: () => memberAreaService.getCourse(courseId!),
    enabled: !isNew && !!courseId,
  });

  const course = courseData?.data;

  // Fetch students
  const { data: studentsData } = useQuery({
    queryKey: ['course-students', courseId],
    queryFn: () => memberAreaService.getCourseStudents(courseId!, { per_page: 100 }),
    enabled: !isNew && !!courseId && activeTab === 'students',
  });
  const students: Enrollment[] = studentsData?.data || [];

  // Form setup
  const { register, handleSubmit, watch, setValue, reset, formState: { errors } } = useForm<CourseFormData>({
    defaultValues: {
      name: '',
      slug: '',
      description: '',
      short_description: '',
      thumbnail_url: '',
      cover_url: '',
      trailer_url: '',
      instructor_name: '',
      instructor_bio: '',
      level: 'all',
      is_published: false,
      tags: [],
      requirements: [],
      objectives: [],
    },
  });

  const tags = watch('tags') || [];
  const requirements = watch('requirements') || [];
  const objectives = watch('objectives') || [];

  // Reset form when course data loads
  useEffect(() => {
    if (course) {
      reset({
        name: course.name || '',
        slug: course.slug || '',
        description: course.description || '',
        short_description: course.short_description || '',
        thumbnail_url: course.thumbnail_url || '',
        cover_url: course.cover_url || '',
        trailer_url: course.trailer_url || '',
        instructor_name: course.instructor_name || '',
        instructor_bio: course.instructor_bio || '',
        level: course.level || 'all',
        is_published: course.is_published || false,
        tags: course.tags || [],
        requirements: course.requirements || [],
        objectives: course.objectives || [],
      });
      if (course.modules?.length) {
        setExpandedModules([course.modules[0].id]);
      }
    }
  }, [course, reset]);

  // Auto-generate slug from name
  const name = watch('name');
  useEffect(() => {
    if (isNew && name) {
      const slug = name
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
      setValue('slug', slug);
    }
  }, [name, isNew, setValue]);

  // ─── Mutations ───────────────────────────────────────

  const saveMutation = useMutation({
    mutationFn: async (data: CourseFormData) => {
      if (isNew) return memberAreaService.createCourse(data);
      return memberAreaService.updateCourse(courseId!, data);
    },
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: ['member-area-courses'] });
      queryClient.invalidateQueries({ queryKey: ['member-area-course', courseId] });
      toast.success(isNew ? 'Curso criado com sucesso' : 'Curso atualizado com sucesso');
      if (isNew && response.data?.id) {
        navigate(`/member-area/courses/${response.data.id}`, { replace: true });
      }
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Erro ao salvar curso');
    },
  });

  const createModuleMutation = useMutation({
    mutationFn: (data: { name: string; description?: string }) =>
      memberAreaService.createModule(courseId!, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['member-area-course', courseId] });
      setShowModuleModal(false);
      toast.success('Módulo criado com sucesso');
    },
    onError: () => toast.error('Erro ao criar módulo'),
  });

  const updateModuleMutation = useMutation({
    mutationFn: ({ moduleId, data }: { moduleId: string; data: Partial<CourseModule> }) =>
      memberAreaService.updateModule(courseId!, moduleId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['member-area-course', courseId] });
      setEditingModule(null);
      toast.success('Módulo atualizado');
    },
    onError: () => toast.error('Erro ao atualizar módulo'),
  });

  const deleteModuleMutation = useMutation({
    mutationFn: (moduleId: string) => memberAreaService.deleteModule(courseId!, moduleId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['member-area-course', courseId] });
      toast.success('Módulo excluído');
    },
    onError: () => toast.error('Erro ao excluir módulo'),
  });

  const createLessonMutation = useMutation({
    mutationFn: ({ moduleId, data }: { moduleId: string; data: Partial<Lesson> }) =>
      memberAreaService.createLesson(courseId!, moduleId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['member-area-course', courseId] });
      setShowLessonModal(null);
      toast.success('Aula criada com sucesso');
    },
    onError: () => toast.error('Erro ao criar aula'),
  });

  const updateLessonMutation = useMutation({
    mutationFn: ({ moduleId, lessonId, data }: { moduleId: string; lessonId: string; data: Partial<Lesson> }) =>
      memberAreaService.updateLesson(courseId!, moduleId, lessonId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['member-area-course', courseId] });
      setEditingLesson(null);
      toast.success('Aula atualizada');
    },
    onError: () => toast.error('Erro ao atualizar aula'),
  });

  const deleteLessonMutation = useMutation({
    mutationFn: ({ moduleId, lessonId }: { moduleId: string; lessonId: string }) =>
      memberAreaService.deleteLesson(courseId!, moduleId, lessonId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['member-area-course', courseId] });
      toast.success('Aula excluída');
    },
    onError: () => toast.error('Erro ao excluir aula'),
  });

  const reorderModulesMutation = useMutation({
    mutationFn: (modules: { id: string; sort_order: number }[]) =>
      memberAreaService.reorderModules(courseId!, modules),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['member-area-course', courseId] }),
  });

  const reorderLessonsMutation = useMutation({
    mutationFn: ({ moduleId, lessons }: { moduleId: string; lessons: { id: string; sort_order: number }[] }) =>
      memberAreaService.reorderLessons(courseId!, moduleId, lessons),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['member-area-course', courseId] }),
  });

  const cancelEnrollmentMutation = useMutation({
    mutationFn: (enrollmentId: string) => memberAreaService.cancelEnrollment(enrollmentId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['course-students', courseId] });
      toast.success('Matrícula cancelada');
    },
    onError: () => toast.error('Erro ao cancelar matrícula'),
  });

  const resetProgressMutation = useMutation({
    mutationFn: (enrollmentId: string) => memberAreaService.resetEnrollmentProgress(enrollmentId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['course-students', courseId] });
      toast.success('Progresso resetado');
    },
    onError: () => toast.error('Erro ao resetar progresso'),
  });

  // ─── Handlers ────────────────────────────────────────

  const onSubmit = (data: CourseFormData) => saveMutation.mutate(data);

  const toggleModule = (moduleId: string) => {
    setExpandedModules(prev =>
      prev.includes(moduleId) ? prev.filter(id => id !== moduleId) : [...prev, moduleId]
    );
  };

  const handleDragEnd = (result: any) => {
    if (!result.destination) return;
    const { source, destination, type } = result;

    if (type === 'MODULE') {
      const modules = [...(course?.modules || [])];
      const [removed] = modules.splice(source.index, 1);
      modules.splice(destination.index, 0, removed);
      reorderModulesMutation.mutate(modules.map((m, i) => ({ id: m.id, sort_order: i })));
    } else if (type === 'LESSON') {
      const moduleId = source.droppableId;
      const moduleIndex = course?.modules?.findIndex((m: CourseModule) => m.id === moduleId);
      if (moduleIndex === undefined || moduleIndex === -1) return;
      const lessons = [...(course?.modules?.[moduleIndex]?.lessons || [])];
      const [removed] = lessons.splice(source.index, 1);
      lessons.splice(destination.index, 0, removed);
      reorderLessonsMutation.mutate({ moduleId, lessons: lessons.map((l, i) => ({ id: l.id, sort_order: i })) });
    }
  };

  const addTag = () => {
    if (tagInput.trim() && !tags.includes(tagInput.trim())) {
      setValue('tags', [...tags, tagInput.trim()]);
      setTagInput('');
    }
  };

  const addReq = () => {
    if (reqInput.trim()) {
      setValue('requirements', [...requirements, reqInput.trim()]);
      setReqInput('');
    }
  };

  const addObj = () => {
    if (objInput.trim()) {
      setValue('objectives', [...objectives, objInput.trim()]);
      setObjInput('');
    }
  };

  // Stats
  const stats = useMemo(() => {
    const modules = course?.modules || [];
    const allLessons = modules.flatMap((m: CourseModule) => m.lessons || []);
    const totalDuration = allLessons.reduce((sum: number, l: Lesson) => sum + (l.duration_seconds || 0), 0);
    return {
      modules: modules.length,
      lessons: allLessons.length,
      videos: allLessons.filter((l: Lesson) => l.content_type === 'video').length,
      texts: allLessons.filter((l: Lesson) => l.content_type === 'text').length,
      quizzes: allLessons.filter((l: Lesson) => l.content_type === 'quiz').length,
      downloads: allLessons.filter((l: Lesson) => l.content_type === 'download').length,
      durationHours: Math.floor(totalDuration / 3600),
      durationMins: Math.floor((totalDuration % 3600) / 60),
      enrollments: course?.enrollments_count || 0,
    };
  }, [course]);

  // ─── Tabs config ─────────────────────────────────────

  const tabsConfig = [
    { id: 'info' as const, label: 'Informações', icon: DocumentTextIcon },
    { id: 'content' as const, label: `Conteúdo (${stats.modules})`, icon: PlayCircleIcon },
    { id: 'students' as const, label: `Alunos (${stats.enrollments})`, icon: UsersIcon },
    { id: 'comments' as const, label: 'Comentários', icon: ChatBubbleLeftRightIcon },
    { id: 'analytics' as const, label: 'Métricas', icon: ChartBarIcon },
  ];

  // ─── Render ──────────────────────────────────────────

  if (!isNew && isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-accent-primary" />
      </div>
    );
  }

  return (
    <div className="space-y-4 sm:space-y-6">
      {/* ═══ Header ═══ */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div className="flex items-center gap-4">
          <Link to="/member-area" className="p-2 hover:bg-surface-hover rounded-lg">
            <ArrowLeftIcon className="h-5 w-5 text-tx-muted" />
          </Link>
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-tx-primary">
              {isNew ? 'Novo Curso' : course?.name || 'Editar Curso'}
            </h1>
            <p className="text-sm text-tx-muted">
              {isNew ? 'Crie um novo curso para sua área de membros' : 'Edite as informações e conteúdo do curso'}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 sm:gap-3 flex-wrap">
          {!isNew && course && memberAreaDomain && (
            <>
              <button
                onClick={() => {
                  const courseUrl = `${memberAreaDomain}/members/login`;
                  navigator.clipboard.writeText(courseUrl).then(() => {
                    toast.success('Link do curso copiado!');
                  }).catch(() => {
                    toast.error('Não foi possível copiar o link');
                  });
                }}
                className="flex items-center gap-2 px-3 py-2 text-sm font-medium text-tx-secondary bg-surface-card border border-border-DEFAULT rounded-lg hover:bg-surface-hover"
                title={`${memberAreaDomain}/members/login`}
              >
                <ClipboardDocumentIcon className="h-4 w-4" />
                Copiar Link
              </button>
              <a
                href={`${memberAreaDomain}/members/login`}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center gap-2 px-3 py-2 text-sm font-medium text-tx-secondary bg-surface-card border border-border-DEFAULT rounded-lg hover:bg-surface-hover"
              >
                <EyeIcon className="h-4 w-4" />
                Visualizar
              </a>
            </>
          )}
          {!isNew && (
            <button
              onClick={() => setValue('is_published', !watch('is_published'))}
              className={`flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg transition-colors ${
                watch('is_published')
                  ? 'bg-status-success-subtle text-status-success'
                  : 'bg-surface-hover text-tx-secondary'
              }`}
            >
              {watch('is_published') ? (
                <><CheckCircleIcon className="h-5 w-5" /> Publicado</>
              ) : (
                <><EyeIcon className="h-5 w-5" /> Rascunho</>
              )}
            </button>
          )}
          <button
            onClick={handleSubmit(onSubmit)}
            disabled={saveMutation.isPending}
            className="px-4 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover disabled:opacity-50"
          >
            {saveMutation.isPending ? 'Salvando...' : isNew ? 'Criar Curso' : 'Salvar'}
          </button>
        </div>
      </div>

      {/* ═══ Stats Bar ═══ */}
      {!isNew && (
        <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
          <MiniStat icon={Bars3Icon} label="Módulos" value={stats.modules} />
          <MiniStat icon={PlayCircleIcon} label="Aulas" value={stats.lessons} />
          <MiniStat icon={VideoCameraIcon} label="Vídeos" value={stats.videos} />
          <MiniStat icon={ClockIcon} label="Duração" value={`${stats.durationHours}h ${stats.durationMins}m`} />
          <MiniStat icon={UsersIcon} label="Alunos" value={stats.enrollments} />
          <MiniStat icon={AcademicCapIcon} label="Nível" value={
            ({ beginner: 'Iniciante', intermediate: 'Intermediário', advanced: 'Avançado', all: 'Todos' } as Record<string, string>)[course?.level || 'all']
          } />
        </div>
      )}

      {/* ═══ Tabs ═══ */}
      {!isNew && (
        <div className="border-b border-border-DEFAULT">
          <nav className="-mb-px flex space-x-6 overflow-x-auto">
            {tabsConfig.map((tab) => (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={`flex items-center gap-2 py-3 px-1 border-b-2 font-medium text-sm whitespace-nowrap transition-colors ${
                  activeTab === tab.id
                    ? 'border-accent-primary text-accent-primary'
                    : 'border-transparent text-tx-muted hover:text-tx-secondary'
                }`}
              >
                <tab.icon className="h-4 w-4" />
                {tab.label}
              </button>
            ))}
          </nav>
        </div>
      )}

      {/* ═══ Info Tab ═══ */}
      {(isNew || activeTab === 'info') && (
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4 sm:space-y-6">
          <div className="grid grid-cols-1 gap-4 sm:gap-6 lg:grid-cols-3">
            {/* Main Info */}
            <div className="lg:col-span-2 space-y-4 sm:space-y-6">
              <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
                <h3 className="text-lg font-semibold text-tx-primary mb-4">Informações Básicas</h3>
                <div className="space-y-4">
                  <FormField label="Nome do Curso *" error={errors.name?.message}>
                    <input type="text" {...register('name', { required: 'Nome é obrigatório' })}
                      className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
                      placeholder="Ex: Curso Completo de React" />
                  </FormField>
                  <FormField label="Slug (URL)">
                    <input type="text" {...register('slug')}
                      className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
                      placeholder="curso-completo-de-react" />
                  </FormField>
                  <FormField label="Descrição Curta">
                    <input type="text" {...register('short_description')}
                      className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
                      placeholder="Uma breve descrição do curso" maxLength={200} />
                  </FormField>
                  <FormField label="Descrição Completa">
                    <textarea {...register('description')} rows={5}
                      className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
                      placeholder="Descreva o conteúdo do curso em detalhes..." />
                  </FormField>
                  <FormField label="Nível">
                    <select {...register('level')}
                      className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary">
                      <option value="all">Todos os níveis</option>
                      <option value="beginner">Iniciante</option>
                      <option value="intermediate">Intermediário</option>
                      <option value="advanced">Avançado</option>
                    </select>
                  </FormField>
                </div>
              </div>

              {/* Instructor */}
              <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
                <h3 className="text-lg font-semibold text-tx-primary mb-4">Instrutor</h3>
                <div className="space-y-4">
                  <FormField label="Nome do Instrutor">
                    <input type="text" {...register('instructor_name')}
                      className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary" />
                  </FormField>
                  <FormField label="Bio do Instrutor">
                    <textarea {...register('instructor_bio')} rows={3}
                      className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary" />
                  </FormField>
                </div>
              </div>

              {/* Tags */}
              <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
                <h3 className="text-lg font-semibold text-tx-primary mb-4">Tags & Categorias</h3>
                <div className="flex gap-2 mb-3">
                  <input type="text" value={tagInput} onChange={(e) => setTagInput(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addTag(); } }}
                    className="flex-1 px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary text-sm"
                    placeholder="Adicionar tag..." />
                  <button type="button" onClick={addTag}
                    className="px-3 py-2 text-sm font-medium text-accent-primary bg-accent-subtle rounded-lg hover:bg-accent-primary hover:text-white transition-colors">
                    <PlusIcon className="h-4 w-4" />
                  </button>
                </div>
                <div className="flex flex-wrap gap-2">
                  {tags.map((tag, i) => (
                    <span key={i} className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-accent-subtle text-accent-primary">
                      <TagIcon className="h-3 w-3" />
                      {tag}
                      <button type="button" onClick={() => setValue('tags', tags.filter((_, j) => j !== i))}
                        className="hover:text-status-error"><XMarkIcon className="h-3 w-3" /></button>
                    </span>
                  ))}
                  {tags.length === 0 && <span className="text-xs text-tx-muted">Nenhuma tag adicionada</span>}
                </div>
              </div>

              {/* Requirements & Objectives */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
                  <h3 className="text-base font-semibold text-tx-primary mb-4">Pré-requisitos</h3>
                  <div className="flex gap-2 mb-3">
                    <input type="text" value={reqInput} onChange={(e) => setReqInput(e.target.value)}
                      onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addReq(); } }}
                      className="flex-1 px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary text-sm"
                      placeholder="Ex: Conhecimento básico em JS" />
                    <button type="button" onClick={addReq}
                      className="px-3 py-2 text-sm text-accent-primary bg-accent-subtle rounded-lg hover:bg-accent-primary hover:text-white transition-colors">
                      <PlusIcon className="h-4 w-4" />
                    </button>
                  </div>
                  <ul className="space-y-2">
                    {requirements.map((req, i) => (
                      <li key={i} className="flex items-center justify-between text-sm text-tx-secondary bg-surface-hover rounded-lg px-3 py-2">
                        <span>• {req}</span>
                        <button type="button" onClick={() => setValue('requirements', requirements.filter((_, j) => j !== i))}
                          className="text-tx-muted hover:text-status-error"><XMarkIcon className="h-4 w-4" /></button>
                      </li>
                    ))}
                    {requirements.length === 0 && <li className="text-xs text-tx-muted">Nenhum pré-requisito</li>}
                  </ul>
                </div>

                <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
                  <h3 className="text-base font-semibold text-tx-primary mb-4">O que o aluno vai aprender</h3>
                  <div className="flex gap-2 mb-3">
                    <input type="text" value={objInput} onChange={(e) => setObjInput(e.target.value)}
                      onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addObj(); } }}
                      className="flex-1 px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary text-sm"
                      placeholder="Ex: Criar apps React do zero" />
                    <button type="button" onClick={addObj}
                      className="px-3 py-2 text-sm text-accent-primary bg-accent-subtle rounded-lg hover:bg-accent-primary hover:text-white transition-colors">
                      <PlusIcon className="h-4 w-4" />
                    </button>
                  </div>
                  <ul className="space-y-2">
                    {objectives.map((obj, i) => (
                      <li key={i} className="flex items-center justify-between text-sm text-tx-secondary bg-surface-hover rounded-lg px-3 py-2">
                        <span className="flex items-center gap-2"><CheckCircleIcon className="h-4 w-4 text-status-success" />{obj}</span>
                        <button type="button" onClick={() => setValue('objectives', objectives.filter((_, j) => j !== i))}
                          className="text-tx-muted hover:text-status-error"><XMarkIcon className="h-4 w-4" /></button>
                      </li>
                    ))}
                    {objectives.length === 0 && <li className="text-xs text-tx-muted">Nenhum objetivo adicionado</li>}
                  </ul>
                </div>
              </div>
            </div>

            {/* Sidebar */}
            <div className="space-y-4 sm:space-y-6">
              <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
                <h3 className="text-lg font-semibold text-tx-primary mb-4">Mídia</h3>
                <div className="space-y-4">
                  <FormField label="URL da Thumbnail">
                    <input type="url" {...register('thumbnail_url')}
                      className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary text-sm"
                      placeholder="https://..." />
                    {watch('thumbnail_url') && (
                      <div className="mt-2 aspect-video bg-surface-hover rounded-lg overflow-hidden">
                        <img src={watch('thumbnail_url')} alt="Preview" className="w-full h-full object-cover" />
                      </div>
                    )}
                  </FormField>
                  <FormField label="URL do Cover">
                    <input type="url" {...register('cover_url')}
                      className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary text-sm"
                      placeholder="https://..." />
                  </FormField>
                  <FormField label="URL do Trailer">
                    <input type="url" {...register('trailer_url')}
                      className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary text-sm"
                      placeholder="https://youtube.com/..." />
                  </FormField>
                </div>
              </div>
            </div>
          </div>
        </form>
      )}

      {/* ═══ Content Tab ═══ */}
      {!isNew && activeTab === 'content' && (
        <div className="space-y-4">
          <div className="flex justify-between items-center">
            <p className="text-sm text-tx-muted flex items-center gap-2">
              <ArrowsUpDownIcon className="h-4 w-4" />
              Arraste para reordenar módulos e aulas
            </p>
            <button
              onClick={() => setShowModuleModal(true)}
              className="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover"
            >
              <PlusIcon className="h-5 w-5" />
              Novo Módulo
            </button>
          </div>

          <DragDropContext onDragEnd={handleDragEnd}>
            <Droppable droppableId="modules" type="MODULE">
              {(provided) => (
                <div {...provided.droppableProps} ref={provided.innerRef} className="space-y-3">
                  {course?.modules?.map((module: CourseModule, index: number) => (
                    <Draggable key={module.id} draggableId={module.id} index={index}>
                      {(provided, snapshot) => (
                        <div
                          ref={provided.innerRef}
                          {...provided.draggableProps}
                          className={`bg-surface-card rounded-card border border-border-DEFAULT overflow-hidden ${snapshot.isDragging ? 'shadow-lg ring-2 ring-accent-primary/30' : ''}`}
                        >
                          {/* Module Header */}
                          <div className="flex items-center gap-3 p-4 bg-surface-page border-b border-border-DEFAULT">
                            <div {...provided.dragHandleProps} className="cursor-grab active:cursor-grabbing">
                              <Bars3Icon className="h-5 w-5 text-tx-muted" />
                            </div>
                            <button onClick={() => toggleModule(module.id)} className="flex-1 flex items-center gap-2 text-left">
                              {expandedModules.includes(module.id) ? (
                                <ChevronDownIcon className="h-5 w-5 text-tx-muted" />
                              ) : (
                                <ChevronUpIcon className="h-5 w-5 text-tx-muted" />
                              )}
                              <div>
                                <span className="font-semibold text-tx-primary">{module.name}</span>
                                <span className="ml-2 text-xs text-tx-muted bg-surface-hover px-2 py-0.5 rounded-full">
                                  {module.lessons?.length || 0} aulas
                                </span>
                              </div>
                            </button>
                            {module.is_free && (
                              <span className="px-2 py-0.5 text-[10px] font-semibold bg-status-success-subtle text-status-success rounded-full">GRÁTIS</span>
                            )}
                            <div className="flex items-center gap-1">
                              <button onClick={() => setShowLessonModal(module.id)} className="p-2 text-tx-muted hover:text-accent-primary rounded-lg hover:bg-surface-hover" title="Adicionar aula">
                                <PlusIcon className="h-4 w-4" />
                              </button>
                              <button onClick={() => setEditingModule(module.id)} className="p-2 text-tx-muted hover:text-tx-secondary rounded-lg hover:bg-surface-hover" title="Editar">
                                <PencilIcon className="h-4 w-4" />
                              </button>
                              <button onClick={() => { if (confirm('Excluir este módulo e todas as suas aulas?')) deleteModuleMutation.mutate(module.id); }}
                                className="p-2 text-tx-muted hover:text-status-error rounded-lg hover:bg-surface-hover" title="Excluir">
                                <TrashIcon className="h-4 w-4" />
                              </button>
                            </div>
                          </div>

                          {/* Lessons */}
                          {expandedModules.includes(module.id) && (
                            <Droppable droppableId={module.id} type="LESSON">
                              {(provided) => (
                                <div {...provided.droppableProps} ref={provided.innerRef} className="divide-y divide-border-DEFAULT">
                                  {module.lessons?.map((lesson: Lesson, lessonIndex: number) => {
                                    const typeConfig = CONTENT_TYPE_CONFIG[lesson.content_type] || CONTENT_TYPE_CONFIG.video;
                                    const TypeIcon = typeConfig.icon;
                                    return (
                                      <Draggable key={lesson.id} draggableId={lesson.id} index={lessonIndex}>
                                        {(provided, snapshot) => (
                                          <div ref={provided.innerRef} {...provided.draggableProps}
                                            className={`flex items-center gap-3 p-4 pl-6 transition-colors ${snapshot.isDragging ? 'bg-accent-subtle' : 'hover:bg-surface-hover'}`}>
                                            <div {...provided.dragHandleProps} className="cursor-grab active:cursor-grabbing">
                                              <Bars3Icon className="h-4 w-4 text-tx-muted" />
                                            </div>
                                            <div className={`p-1.5 rounded-lg bg-surface-hover ${typeConfig.color}`}>
                                              <TypeIcon className="h-4 w-4" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                              <p className="text-sm font-medium text-tx-primary truncate">{lesson.name}</p>
                                              <div className="flex items-center gap-2 mt-0.5">
                                                <span className="text-[10px] font-medium text-tx-muted uppercase">{typeConfig.label}</span>
                                                {lesson.content_type === 'video' && lesson.duration_seconds > 0 && (
                                                  <span className="text-[10px] text-tx-muted">
                                                    {Math.floor(lesson.duration_seconds / 60)}:{String(lesson.duration_seconds % 60).padStart(2, '0')}
                                                  </span>
                                                )}
                                              </div>
                                            </div>
                                            {lesson.is_free && (
                                              <span className="px-2 py-0.5 text-[10px] font-semibold bg-status-success-subtle text-status-success rounded-full">GRÁTIS</span>
                                            )}
                                            {!lesson.is_published && (
                                              <span className="px-2 py-0.5 text-[10px] font-semibold bg-surface-hover text-tx-muted rounded-full">RASCUNHO</span>
                                            )}
                                            <div className="flex items-center gap-1">
                                              <button onClick={() => setEditingLesson({ moduleId: module.id, lessonId: lesson.id })}
                                                className="p-1.5 text-tx-muted hover:text-tx-secondary rounded-lg hover:bg-surface-hover">
                                                <PencilIcon className="h-3.5 w-3.5" />
                                              </button>
                                              <button onClick={() => { if (confirm('Excluir esta aula?')) deleteLessonMutation.mutate({ moduleId: module.id, lessonId: lesson.id }); }}
                                                className="p-1.5 text-tx-muted hover:text-status-error rounded-lg hover:bg-surface-hover">
                                                <TrashIcon className="h-3.5 w-3.5" />
                                              </button>
                                            </div>
                                          </div>
                                        )}
                                      </Draggable>
                                    );
                                  })}
                                  {provided.placeholder}
                                  {(!module.lessons || module.lessons.length === 0) && (
                                    <button
                                      onClick={() => setShowLessonModal(module.id)}
                                      className="w-full p-4 text-center text-sm text-tx-muted hover:text-accent-primary hover:bg-accent-subtle/30 transition-colors border-t border-dashed border-border-DEFAULT"
                                    >
                                      <PlusIcon className="h-5 w-5 mx-auto mb-1" />
                                      Adicionar primeira aula
                                    </button>
                                  )}
                                </div>
                              )}
                            </Droppable>
                          )}
                        </div>
                      )}
                    </Draggable>
                  ))}
                  {provided.placeholder}
                </div>
              )}
            </Droppable>
          </DragDropContext>

          {(!course?.modules || course.modules.length === 0) && (
            <div className="text-center py-16 bg-surface-card rounded-card border border-dashed border-border-DEFAULT">
              <Bars3Icon className="h-12 w-12 text-tx-muted mx-auto mb-3" />
              <h3 className="text-lg font-medium text-tx-primary mb-1">Nenhum módulo criado</h3>
              <p className="text-sm text-tx-muted mb-4">Organize seu curso em módulos e adicione aulas</p>
              <button onClick={() => setShowModuleModal(true)}
                className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover">
                <PlusIcon className="h-5 w-5" /> Criar primeiro módulo
              </button>
            </div>
          )}
        </div>
      )}

      {/* ═══ Students Tab ═══ */}
      {!isNew && activeTab === 'students' && (
        <StudentsTab
          students={students}
          courseId={courseId!}
          onCancel={(id) => cancelEnrollmentMutation.mutate(id)}
          onResetProgress={(id) => resetProgressMutation.mutate(id)}
        />
      )}

      {/* ═══ Comments Tab ═══ */}
      {!isNew && activeTab === 'comments' && (
        <CommentsTab course={course} />
      )}

      {/* ═══ Analytics Tab ═══ */}
      {!isNew && activeTab === 'analytics' && (
        <AnalyticsTab course={course} students={students} stats={stats} />
      )}

      {/* ═══ Modals ═══ */}
      {showModuleModal && (
        <ModuleModal onClose={() => setShowModuleModal(false)}
          onSave={(data) => createModuleMutation.mutate(data)}
          isLoading={createModuleMutation.isPending} />
      )}
      {showLessonModal && (
        <LessonModal onClose={() => setShowLessonModal(null)}
          onSave={(data) => createLessonMutation.mutate({ moduleId: showLessonModal, data })}
          isLoading={createLessonMutation.isPending} />
      )}
      {editingModule && (
        <ModuleModal module={course?.modules?.find((m: CourseModule) => m.id === editingModule)}
          onClose={() => setEditingModule(null)}
          onSave={(data) => updateModuleMutation.mutate({ moduleId: editingModule, data })}
          isLoading={updateModuleMutation.isPending} />
      )}
      {editingLesson && (
        <LessonModal
          lesson={course?.modules?.find((m: CourseModule) => m.id === editingLesson.moduleId)?.lessons?.find((l: Lesson) => l.id === editingLesson.lessonId)}
          onClose={() => setEditingLesson(null)}
          onSave={(data) => updateLessonMutation.mutate({ ...editingLesson, data })}
          isLoading={updateLessonMutation.isPending} />
      )}
    </div>
  );
}

// ─── Small Components ────────────────────────────────

function FormField({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return (
    <div>
      <label className="block text-sm font-medium text-tx-secondary mb-1">{label}</label>
      {children}
      {error && <p className="mt-1 text-sm text-status-error">{error}</p>}
    </div>
  );
}

function MiniStat({ icon: Icon, label, value }: { icon: any; label: string; value: string | number }) {
  return (
    <div className="bg-surface-card rounded-xl border border-border-DEFAULT p-3 flex items-center gap-3">
      <div className="p-2 bg-accent-subtle rounded-lg">
        <Icon className="h-4 w-4 text-accent-primary" />
      </div>
      <div>
        <p className="text-xs text-tx-muted">{label}</p>
        <p className="text-sm font-bold text-tx-primary">{value}</p>
      </div>
    </div>
  );
}

// ─── Students Tab Component ──────────────────────────

function StudentsTab({ students, courseId, onCancel, onResetProgress }: {
  students: Enrollment[];
  courseId: string;
  onCancel: (id: string) => void;
  onResetProgress: (id: string) => void;
}) {
  const [search, setSearch] = useState('');
  const [showEnrollModal, setShowEnrollModal] = useState(false);
  const [generatedCredentials, setGeneratedCredentials] = useState<{ email: string; password: string } | null>(null);
  const queryClient = useQueryClient();

  const enrollMutation = useMutation({
    mutationFn: (data: { course_id: string; user_email: string; user_name?: string }) =>
      memberAreaService.createEnrollment(data),
    onSuccess: (response: any) => {
      queryClient.invalidateQueries({ queryKey: ['course-students', courseId] });
      setShowEnrollModal(false);
      
      // Check if a new user was created with generated password
      if (response?.is_new_user && response?.generated_password) {
        setGeneratedCredentials({
          email: response.data?.user?.email || '',
          password: response.generated_password,
        });
      } else {
        toast.success('Aluno matriculado com sucesso');
      }
    },
    onError: (err: any) => toast.error(err.response?.data?.message || 'Erro ao matricular'),
  });

  const resetPasswordMutation = useMutation({
    mutationFn: (enrollmentId: string) => memberAreaService.resetMemberPassword(enrollmentId),
    onSuccess: (response: any) => {
      setGeneratedCredentials({
        email: response.data?.email || '',
        password: response.generated_password,
      });
    },
    onError: (err: any) => toast.error(err.response?.data?.message || 'Erro ao resetar senha'),
  });

  const filtered = students.filter((s) => {
    if (!search) return true;
    const q = search.toLowerCase();
    return s.user?.name?.toLowerCase().includes(q) || s.user?.email?.toLowerCase().includes(q);
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 sm:gap-4">
        <div className="relative flex-1 w-full max-w-md">
          <MagnifyingGlassIcon className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-tx-muted" />
          <input type="text" value={search} onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar aluno por nome ou email..."
            className="w-full pl-10 pr-4 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary text-sm" />
        </div>
        <button onClick={() => setShowEnrollModal(true)}
          className="flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover">
          <PlusIcon className="h-4 w-4" /> Matricular Aluno
        </button>
      </div>

      {filtered.length === 0 ? (
        <div className="text-center py-16 bg-surface-card rounded-card border border-border-DEFAULT">
          <UsersIcon className="h-12 w-12 text-tx-muted mx-auto mb-3" />
          <h3 className="text-lg font-medium text-tx-primary mb-1">Nenhum aluno matriculado</h3>
          <p className="text-sm text-tx-muted">Matricule alunos manualmente ou vincule o curso a um produto</p>
        </div>
      ) : (
        <div className="bg-surface-card rounded-card border border-border-DEFAULT overflow-hidden">
          <table className="w-full">
            <thead>
              <tr className="border-b border-border-DEFAULT bg-surface-page">
                <th className="text-left px-4 py-3 text-xs font-semibold text-tx-muted uppercase">Aluno</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-tx-muted uppercase">Status</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-tx-muted uppercase">Progresso</th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-tx-muted uppercase">Matrícula</th>
                <th className="text-right px-4 py-3 text-xs font-semibold text-tx-muted uppercase">Ações</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-DEFAULT">
              {filtered.map((enrollment) => (
                <tr key={enrollment.id} className="hover:bg-surface-hover transition-colors">
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <div className="h-8 w-8 rounded-full bg-accent-subtle flex items-center justify-center text-sm font-semibold text-accent-primary">
                        {enrollment.user?.name?.charAt(0) || '?'}
                      </div>
                      <div>
                        <p className="text-sm font-medium text-tx-primary">{enrollment.user?.name}</p>
                        <p className="text-xs text-tx-muted">{enrollment.user?.email}</p>
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                      enrollment.status === 'active' ? 'bg-status-success-subtle text-status-success' :
                      enrollment.status === 'expired' ? 'bg-status-warning-subtle text-status-warning' :
                      'bg-status-error-subtle text-status-error'
                    }`}>
                      {enrollment.status === 'active' ? 'Ativo' : enrollment.status === 'expired' ? 'Expirado' : 'Cancelado'}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <div className="flex-1 h-2 bg-surface-hover rounded-full overflow-hidden max-w-[100px]">
                        <div className="h-full bg-accent-primary rounded-full transition-all"
                          style={{ width: `${enrollment.progress_percent || enrollment.progress_percentage || 0}%` }} />
                      </div>
                      <span className="text-xs font-medium text-tx-secondary">
                        {enrollment.progress_percent || enrollment.progress_percentage || 0}%
                      </span>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-xs text-tx-muted">
                    {enrollment.enrolled_at ? new Date(enrollment.enrolled_at).toLocaleDateString('pt-BR') : '-'}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-1">
                      <button onClick={() => resetPasswordMutation.mutate(enrollment.id)} title="Resetar senha"
                        className="p-1.5 text-tx-muted hover:text-accent-primary rounded-lg hover:bg-surface-hover"
                        disabled={resetPasswordMutation.isPending}>
                        <KeyIcon className="h-3.5 w-3.5" />
                      </button>
                      <button onClick={() => onResetProgress(enrollment.id)} title="Resetar progresso"
                        className="p-1.5 text-tx-muted hover:text-status-warning rounded-lg hover:bg-surface-hover">
                        <ArrowLeftIcon className="h-3.5 w-3.5" />
                      </button>
                      <button onClick={() => { if (confirm('Cancelar matrícula?')) onCancel(enrollment.id); }} title="Cancelar matrícula"
                        className="p-1.5 text-tx-muted hover:text-status-error rounded-lg hover:bg-surface-hover">
                        <TrashIcon className="h-3.5 w-3.5" />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Enroll Modal */}
      {showEnrollModal && (
        <EnrollModal
          onClose={() => setShowEnrollModal(false)}
          onSave={(data) => enrollMutation.mutate({ ...data, course_id: courseId })}
          isLoading={enrollMutation.isPending}
        />
      )}

      {/* Generated Credentials Modal */}
      {generatedCredentials && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" onClick={() => setGeneratedCredentials(null)}>
          <div className="bg-surface-card rounded-xl border border-border-DEFAULT p-4 sm:p-6 w-full max-w-md shadow-2xl" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-semibold text-tx-primary">Aluno Matriculado!</h3>
              <button onClick={() => setGeneratedCredentials(null)} className="p-1 hover:bg-surface-hover rounded-lg">
                <XMarkIcon className="h-5 w-5 text-tx-muted" />
              </button>
            </div>
            <div className="bg-status-success-subtle border border-status-success/30 rounded-lg p-4 mb-4">
              <p className="text-sm text-status-success font-medium mb-2">Credenciais de acesso geradas:</p>
              <div className="space-y-2 text-sm">
                <div className="flex items-center justify-between bg-surface-card rounded-lg p-2">
                  <span className="text-tx-muted">Email:</span>
                  <span className="text-tx-primary font-mono">{generatedCredentials.email}</span>
                </div>
                <div className="flex items-center justify-between bg-surface-card rounded-lg p-2">
                  <span className="text-tx-muted">Senha:</span>
                  <span className="text-tx-primary font-mono font-semibold">{generatedCredentials.password}</span>
                </div>
              </div>
            </div>
            <p className="text-xs text-tx-muted mb-4">
              Um email com as credenciais foi enviado para o aluno. Você também pode copiar e compartilhar diretamente.
            </p>
            <div className="flex justify-end gap-3">
              <button
                onClick={() => {
                  navigator.clipboard.writeText(`Email: ${generatedCredentials.email}\nSenha: ${generatedCredentials.password}`);
                  toast.success('Credenciais copiadas!');
                }}
                className="flex items-center gap-2 px-4 py-2 text-sm font-medium text-tx-secondary bg-surface-hover rounded-lg hover:bg-surface-card border border-border-DEFAULT"
              >
                <ClipboardDocumentIcon className="h-4 w-4" />
                Copiar
              </button>
              <button
                onClick={() => setGeneratedCredentials(null)}
                className="px-4 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover"
              >
                Fechar
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Comments Tab ────────────────────────────────────

function CommentsTab({ course }: { course: any }) {
  const allComments: Array<LessonComment & { lesson_name: string; module_name: string }> = useMemo(() => {
    if (!course?.modules) return [];
    const comments: Array<LessonComment & { lesson_name: string; module_name: string }> = [];
    for (const module of course.modules) {
      for (const lesson of (module.lessons || [])) {
        for (const comment of (lesson.comments || [])) {
          comments.push({ ...comment, lesson_name: lesson.name, module_name: module.name });
        }
      }
    }
    return comments.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());
  }, [course]);

  if (allComments.length === 0) {
    return (
      <div className="text-center py-16 bg-surface-card rounded-card border border-border-DEFAULT">
        <ChatBubbleLeftRightIcon className="h-12 w-12 text-tx-muted mx-auto mb-3" />
        <h3 className="text-lg font-medium text-tx-primary mb-1">Nenhum comentário ainda</h3>
        <p className="text-sm text-tx-muted">Os comentários dos alunos nas aulas aparecerão aqui</p>
      </div>
    );
  }

  return (
    <div className="space-y-3">
      <p className="text-sm text-tx-muted">{allComments.length} comentário(s) no total</p>
      {allComments.map((comment) => (
        <div key={comment.id} className="bg-surface-card rounded-card border border-border-DEFAULT p-4">
          <div className="flex items-start gap-3">
            <div className="h-8 w-8 rounded-full bg-accent-subtle flex items-center justify-center text-sm font-semibold text-accent-primary flex-shrink-0">
              {comment.member?.name?.charAt(0) || '?'}
            </div>
            <div className="flex-1 min-w-0">
              <div className="flex items-center justify-between gap-2">
                <div>
                  <span className="text-sm font-medium text-tx-primary">{comment.member?.name || 'Anônimo'}</span>
                  <span className="mx-2 text-tx-muted">·</span>
                  <span className="text-xs text-tx-muted">{new Date(comment.created_at).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</span>
                </div>
              </div>
              <p className="mt-1 text-sm text-tx-secondary">{comment.content}</p>
              <div className="mt-2 flex items-center gap-2">
                <span className="text-[10px] font-medium text-tx-muted bg-surface-hover px-2 py-0.5 rounded-full">
                  {comment.module_name}
                </span>
                <span className="text-[10px] font-medium text-accent-primary bg-accent-subtle px-2 py-0.5 rounded-full">
                  {comment.lesson_name}
                </span>
              </div>
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}

// ─── Analytics Tab ───────────────────────────────────

function AnalyticsTab({ course, students, stats }: { course: any; students: Enrollment[]; stats: any }) {
  const activeStudents = students.filter(s => s.status === 'active');
  const completedStudents = students.filter(s => (s.progress_percent || s.progress_percentage || 0) >= 100);
  const avgProgress = students.length > 0
    ? Math.round(students.reduce((sum, s) => sum + (s.progress_percent || s.progress_percentage || 0), 0) / students.length)
    : 0;

  const ranges = [
    { label: '0-25%', min: 0, max: 25, color: 'bg-red-400' },
    { label: '26-50%', min: 26, max: 50, color: 'bg-amber-400' },
    { label: '51-75%', min: 51, max: 75, color: 'bg-blue-400' },
    { label: '76-100%', min: 76, max: 100, color: 'bg-emerald-400' },
  ];

  return (
    <div className="space-y-4 sm:space-y-6">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-surface-card rounded-card border border-border-DEFAULT p-5 text-center">
          <p className="text-3xl font-bold text-tx-primary">{students.length}</p>
          <p className="text-xs text-tx-muted mt-1">Total de Alunos</p>
        </div>
        <div className="bg-surface-card rounded-card border border-border-DEFAULT p-5 text-center">
          <p className="text-3xl font-bold text-status-success">{activeStudents.length}</p>
          <p className="text-xs text-tx-muted mt-1">Alunos Ativos</p>
        </div>
        <div className="bg-surface-card rounded-card border border-border-DEFAULT p-5 text-center">
          <p className="text-3xl font-bold text-accent-primary">{avgProgress}%</p>
          <p className="text-xs text-tx-muted mt-1">Progresso Médio</p>
        </div>
        <div className="bg-surface-card rounded-card border border-border-DEFAULT p-5 text-center">
          <p className="text-3xl font-bold text-amber-400">{completedStudents.length}</p>
          <p className="text-xs text-tx-muted mt-1">Concluíram o Curso</p>
        </div>
      </div>

      <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
        <h3 className="text-base font-semibold text-tx-primary mb-4">Distribuição de Progresso</h3>
        {students.length === 0 ? (
          <p className="text-sm text-tx-muted text-center py-6">Sem dados de progresso ainda</p>
        ) : (
          <div className="space-y-3">
            {ranges.map((range) => {
              const count = students.filter(s => {
                const p = s.progress_percent || s.progress_percentage || 0;
                return p >= range.min && p <= range.max;
              }).length;
              const pct = students.length > 0 ? Math.round((count / students.length) * 100) : 0;
              return (
                <div key={range.label} className="flex items-center gap-3">
                  <span className="text-xs text-tx-muted w-16">{range.label}</span>
                  <div className="flex-1 h-3 bg-surface-hover rounded-full overflow-hidden">
                    <div className={`h-full ${range.color} rounded-full transition-all`} style={{ width: `${pct}%` }} />
                  </div>
                  <span className="text-xs font-medium text-tx-secondary w-16 text-right">{count} ({pct}%)</span>
                </div>
              );
            })}
          </div>
        )}
      </div>

      <div className="bg-surface-card rounded-card border border-border-DEFAULT p-4 sm:p-6">
        <h3 className="text-base font-semibold text-tx-primary mb-4">Composição do Conteúdo</h3>
        <div className="grid grid-cols-2 sm:grid-cols-5 gap-4">
          {Object.entries(CONTENT_TYPE_CONFIG).map(([key, config]) => {
            const Icon = config.icon;
            const count = key === 'video' ? stats.videos :
                         key === 'text' ? stats.texts :
                         key === 'quiz' ? stats.quizzes :
                         key === 'download' ? stats.downloads : 0;
            return (
              <div key={key} className="flex flex-col items-center gap-2 p-4 rounded-xl bg-surface-hover">
                <Icon className={`h-6 w-6 ${config.color}`} />
                <span className="text-lg font-bold text-tx-primary">{count}</span>
                <span className="text-xs text-tx-muted">{config.label}</span>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

// ─── Module Modal ────────────────────────────────────

function ModuleModal({ module, onClose, onSave, isLoading }: {
  module?: CourseModule;
  onClose: () => void;
  onSave: (data: { name: string; description?: string; is_free?: boolean }) => void;
  isLoading: boolean;
}) {
  const [name, setName] = useState(module?.name || '');
  const [description, setDescription] = useState(module?.description || '');
  const [isFree, setIsFree] = useState(module?.is_free || false);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" onClick={onClose}>
      <div className="bg-surface-card rounded-xl border border-border-DEFAULT p-4 sm:p-6 w-full max-w-md shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold text-tx-primary">{module ? 'Editar Módulo' : 'Novo Módulo'}</h3>
          <button onClick={onClose} className="p-1 hover:bg-surface-hover rounded-lg"><XMarkIcon className="h-5 w-5 text-tx-muted" /></button>
        </div>
        <div className="space-y-4">
          <FormField label="Nome do Módulo *">
            <input type="text" value={name} onChange={(e) => setName(e.target.value)} autoFocus
              className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary" placeholder="Ex: Módulo 1 - Introdução" />
          </FormField>
          <FormField label="Descrição">
            <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={3}
              className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary" placeholder="Descreva o conteúdo deste módulo..." />
          </FormField>
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={isFree} onChange={(e) => setIsFree(e.target.checked)}
              className="rounded border-border-DEFAULT text-accent-primary focus:ring-accent-primary" />
            <span className="text-sm text-tx-secondary">Módulo gratuito (preview)</span>
          </label>
        </div>
        <div className="flex justify-end gap-3 mt-6">
          <button onClick={onClose} className="px-4 py-2 text-sm font-medium text-tx-secondary hover:bg-surface-hover rounded-lg">Cancelar</button>
          <button onClick={() => onSave({ name, description, is_free: isFree })} disabled={!name || isLoading}
            className="px-4 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover disabled:opacity-50">
            {isLoading ? 'Salvando...' : 'Salvar'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Lesson Modal (Full) ─────────────────────────────

function LessonModal({ lesson, onClose, onSave, isLoading }: {
  lesson?: Lesson;
  onClose: () => void;
  onSave: (data: Partial<Lesson>) => void;
  isLoading: boolean;
  modules?: CourseModule[];
  currentModuleId?: string;
}) {
  const [name, setName] = useState(lesson?.name || '');
  const [description, setDescription] = useState(lesson?.description || '');
  const [contentType, setContentType] = useState<Lesson['content_type']>(lesson?.content_type || 'video');
  const [videoUrl, setVideoUrl] = useState(lesson?.video_url || '');
  const [videoProvider, setVideoProvider] = useState<'youtube' | 'vimeo' | 'bunny' | 'custom'>(lesson?.video_provider || 'youtube');
  const [durationSeconds, setDurationSeconds] = useState(lesson?.duration_seconds || 0);
  const [content, setContent] = useState(lesson?.content || '');
  const [isFree, setIsFree] = useState(lesson?.is_free || false);
  const [isPublished, setIsPublished] = useState(lesson?.is_published !== false);
  const [isDownloadable, setIsDownloadable] = useState(lesson?.is_downloadable || false);

  const durationMinutes = Math.floor(durationSeconds / 60);
  const durationSecs = durationSeconds % 60;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm overflow-y-auto py-8" onClick={onClose}>
      <div className="bg-surface-card rounded-xl border border-border-DEFAULT p-4 sm:p-6 w-full max-w-2xl mx-4 shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4 sm:mb-6">
          <h3 className="text-lg font-semibold text-tx-primary">{lesson ? 'Editar Aula' : 'Nova Aula'}</h3>
          <button onClick={onClose} className="p-1 hover:bg-surface-hover rounded-lg"><XMarkIcon className="h-5 w-5 text-tx-muted" /></button>
        </div>

        <div className="space-y-5">
          <FormField label="Nome da Aula *">
            <input type="text" value={name} onChange={(e) => setName(e.target.value)} autoFocus
              className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
              placeholder="Ex: Aula 1 - Bem-vindo ao curso" />
          </FormField>

          {/* Content Type Selector */}
          <div>
            <label className="block text-sm font-medium text-tx-secondary mb-2">Tipo de Conteúdo</label>
            <div className="grid grid-cols-3 sm:grid-cols-5 gap-2">
              {Object.entries(CONTENT_TYPE_CONFIG).map(([key, config]) => {
                const Icon = config.icon;
                return (
                  <button key={key} type="button" onClick={() => setContentType(key as Lesson['content_type'])}
                    className={`flex flex-col items-center gap-1.5 p-3 rounded-xl border-2 transition-all text-center ${
                      contentType === key
                        ? 'border-accent-primary bg-accent-subtle'
                        : 'border-border-DEFAULT hover:border-accent-primary/30 bg-surface-card'
                    }`}>
                    <Icon className={`h-5 w-5 ${contentType === key ? 'text-accent-primary' : 'text-tx-muted'}`} />
                    <span className={`text-xs font-medium ${contentType === key ? 'text-accent-primary' : 'text-tx-muted'}`}>{config.label}</span>
                  </button>
                );
              })}
            </div>
          </div>

          {/* Video config */}
          {contentType === 'video' && (
            <div className="space-y-4 p-4 rounded-xl bg-surface-hover border border-border-DEFAULT">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <FormField label="Provedor">
                  <select value={videoProvider} onChange={(e) => setVideoProvider(e.target.value as any)}
                    className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary">
                    <option value="youtube">YouTube</option>
                    <option value="vimeo">Vimeo</option>
                    <option value="bunny">Bunny Stream</option>
                    <option value="custom">URL Personalizada</option>
                  </select>
                </FormField>
                <FormField label="Duração">
                  <div className="flex gap-2">
                    <div className="flex items-center gap-1">
                      <input type="number" value={durationMinutes} min={0}
                        onChange={(e) => setDurationSeconds(parseInt(e.target.value || '0') * 60 + durationSecs)}
                        className="w-16 px-2 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary text-center" />
                      <span className="text-xs text-tx-muted">min</span>
                    </div>
                    <div className="flex items-center gap-1">
                      <input type="number" value={durationSecs} min={0} max={59}
                        onChange={(e) => setDurationSeconds(durationMinutes * 60 + parseInt(e.target.value || '0'))}
                        className="w-16 px-2 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary text-center" />
                      <span className="text-xs text-tx-muted">seg</span>
                    </div>
                  </div>
                </FormField>
              </div>
              <FormField label="URL do Vídeo">
                <input type="url" value={videoUrl} onChange={(e) => setVideoUrl(e.target.value)}
                  className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
                  placeholder={videoProvider === 'youtube' ? 'https://youtube.com/watch?v=...' : videoProvider === 'vimeo' ? 'https://vimeo.com/...' : 'https://...'} />
              </FormField>
            </div>
          )}

          {/* Text content */}
          {contentType === 'text' && (
            <div className="space-y-2">
              <label className="block text-sm font-medium text-tx-secondary">Conteúdo (HTML)</label>
              <textarea value={content} onChange={(e) => setContent(e.target.value)} rows={10}
                className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary font-mono text-sm"
                placeholder="<h2>Título da Aula</h2><p>Conteúdo em HTML...</p>" />
              <p className="text-xs text-tx-muted">Suporta HTML. Use tags como &lt;h2&gt;, &lt;p&gt;, &lt;ul&gt;, &lt;strong&gt;, &lt;img&gt;, etc.</p>
            </div>
          )}

          {/* Quiz */}
          {contentType === 'quiz' && (
            <div className="p-6 rounded-xl bg-amber-500/5 border border-amber-500/20 text-center">
              <QuestionMarkCircleIcon className="h-10 w-10 text-amber-400 mx-auto mb-2" />
              <p className="text-sm text-tx-secondary">Configure o quiz no campo de conteúdo abaixo.</p>
              <p className="text-xs text-tx-muted mt-1">Use formato JSON para definir perguntas e alternativas.</p>
              <textarea value={content} onChange={(e) => setContent(e.target.value)} rows={8}
                className="w-full mt-4 px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary font-mono text-sm"
                placeholder={`[{"question":"Pergunta 1?","options":["A","B","C","D"],"correct":0}]`} />
            </div>
          )}

          {/* Download */}
          {contentType === 'download' && (
            <div className="space-y-4 p-4 rounded-xl bg-emerald-500/5 border border-emerald-500/20">
              <FormField label="URL do Arquivo">
                <input type="url" value={videoUrl} onChange={(e) => setVideoUrl(e.target.value)}
                  className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
                  placeholder="https://storage.exemplo.com/arquivo.pdf" />
              </FormField>
              <FormField label="Descrição do Material">
                <textarea value={content} onChange={(e) => setContent(e.target.value)} rows={3}
                  className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
                  placeholder="Descreva o material disponível para download..." />
              </FormField>
            </div>
          )}

          {/* Live */}
          {contentType === 'live' && (
            <div className="space-y-4 p-4 rounded-xl bg-red-500/5 border border-red-500/20">
              <FormField label="URL da Transmissão">
                <input type="url" value={videoUrl} onChange={(e) => setVideoUrl(e.target.value)}
                  className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
                  placeholder="https://zoom.us/j/... ou YouTube Live URL" />
              </FormField>
              <FormField label="Informações da Live">
                <textarea value={content} onChange={(e) => setContent(e.target.value)} rows={3}
                  className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
                  placeholder="Data, horário e informações sobre a transmissão ao vivo..." />
              </FormField>
            </div>
          )}

          {/* Description */}
          <FormField label="Descrição da Aula">
            <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={2}
              className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
              placeholder="Breve descrição sobre o que o aluno vai aprender nesta aula..." />
          </FormField>

          {/* Options */}
          <div className="flex flex-wrap gap-4 pt-2">
            <label className="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" checked={isFree} onChange={(e) => setIsFree(e.target.checked)}
                className="rounded border-border-DEFAULT text-accent-primary focus:ring-accent-primary" />
              <span className="text-sm text-tx-secondary">Aula gratuita (preview)</span>
            </label>
            <label className="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" checked={isPublished} onChange={(e) => setIsPublished(e.target.checked)}
                className="rounded border-border-DEFAULT text-accent-primary focus:ring-accent-primary" />
              <span className="text-sm text-tx-secondary">Publicada</span>
            </label>
            <label className="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" checked={isDownloadable} onChange={(e) => setIsDownloadable(e.target.checked)}
                className="rounded border-border-DEFAULT text-accent-primary focus:ring-accent-primary" />
              <span className="text-sm text-tx-secondary">Permitir download</span>
            </label>
          </div>
        </div>

        <div className="flex justify-end gap-3 mt-6 pt-4 border-t border-border-DEFAULT">
          <button onClick={onClose}
            className="px-4 py-2 text-sm font-medium text-tx-secondary hover:bg-surface-hover rounded-lg">Cancelar</button>
          <button
            onClick={() => onSave({
              name, description, content_type: contentType,
              video_url: videoUrl, video_provider: videoProvider,
              duration_seconds: durationSeconds, content,
              is_free: isFree, is_published: isPublished, is_downloadable: isDownloadable,
            })}
            disabled={!name || isLoading}
            className="px-6 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover disabled:opacity-50">
            {isLoading ? 'Salvando...' : 'Salvar Aula'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Enroll Modal ────────────────────────────────────

function EnrollModal({ onClose, onSave, isLoading }: {
  onClose: () => void;
  onSave: (data: { user_email: string; user_name?: string }) => void;
  isLoading: boolean;
}) {
  const [email, setEmail] = useState('');
  const [name, setName] = useState('');

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" onClick={onClose}>
      <div className="bg-surface-card rounded-xl border border-border-DEFAULT p-4 sm:p-6 w-full max-w-md shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold text-tx-primary">Matricular Aluno</h3>
          <button onClick={onClose} className="p-1 hover:bg-surface-hover rounded-lg"><XMarkIcon className="h-5 w-5 text-tx-muted" /></button>
        </div>
        <div className="space-y-4">
          <FormField label="Email do Aluno *">
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoFocus
              className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
              placeholder="aluno@email.com" />
          </FormField>
          <FormField label="Nome (opcional)">
            <input type="text" value={name} onChange={(e) => setName(e.target.value)}
              className="w-full px-3 py-2 border border-border-DEFAULT rounded-lg bg-surface-card text-tx-primary"
              placeholder="Nome do aluno" />
          </FormField>
        </div>
        <div className="flex justify-end gap-3 mt-6">
          <button onClick={onClose} className="px-4 py-2 text-sm font-medium text-tx-secondary hover:bg-surface-hover rounded-lg">Cancelar</button>
          <button onClick={() => onSave({ user_email: email, user_name: name || undefined })} disabled={!email || isLoading}
            className="px-4 py-2 text-sm font-medium text-white bg-accent-primary rounded-element hover:bg-accent-hover disabled:opacity-50">
            {isLoading ? 'Matriculando...' : 'Matricular'}
          </button>
        </div>
      </div>
    </div>
  );
}
