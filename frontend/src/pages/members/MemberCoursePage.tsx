import { useState, useEffect, useMemo } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import DOMPurify from 'dompurify';
import { safeWindowOpen } from '@/lib/safeUrl';
import {
  PlayIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  CheckCircleIcon,
  LockClosedIcon,
  ListBulletIcon,
  XMarkIcon,
  ArrowLeftIcon,
  BookOpenIcon,
  ClockIcon,
  ChatBubbleLeftIcon,
  DocumentTextIcon,
  ArrowDownTrayIcon,
  QuestionMarkCircleIcon,
  SignalIcon,
  TrophyIcon,
} from '@heroicons/react/24/solid';
import { memberAreaService, CourseModule, Lesson } from '@/services/api/memberArea.service';
import toast from 'react-hot-toast';

// ─── Content Type Helpers ────────────────────────────

const LESSON_ICONS: Record<string, typeof PlayIcon> = {
  video: PlayIcon,
  text: DocumentTextIcon,
  quiz: QuestionMarkCircleIcon,
  download: ArrowDownTrayIcon,
  live: SignalIcon,
};

export default function MemberCoursePage() {
  const { courseId, lessonId } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [currentLesson, setCurrentLesson] = useState<Lesson | null>(null);
  const [showComments, setShowComments] = useState(false);
  const [newComment, setNewComment] = useState('');

  // Fetch course
  const { data: courseData, isLoading } = useQuery({
    queryKey: ['member-course', courseId],
    queryFn: () => memberAreaService.getMemberCourse(courseId!),
  });

  const course = courseData?.data;

  // Flatten lessons
  const allLessons = useMemo(
    () => course?.modules?.flatMap((m: CourseModule) => m.lessons || []) || [],
    [course]
  );

  // Navigation
  const currentIndex = allLessons.findIndex((l: Lesson) => l.id === currentLesson?.id);
  const prevLesson = currentIndex > 0 ? allLessons[currentIndex - 1] : null;
  const nextLesson = currentIndex < allLessons.length - 1 ? allLessons[currentIndex + 1] : null;

  // Progress
  const totalLessons = allLessons.length;
  const completedLessons = allLessons.filter((l: Lesson) => l.progress?.completed).length;
  const progressPercentage = totalLessons > 0 ? Math.round((completedLessons / totalLessons) * 100) : 0;

  // Set current lesson
  useEffect(() => {
    if (!course?.modules) return;
    if (lessonId) {
      for (const mod of course.modules) {
        const found = mod.lessons?.find((l: Lesson) => l.id === lessonId);
        if (found) { setCurrentLesson(found); return; }
      }
    }
    // Auto-select first lesson
    for (const mod of course.modules) {
      if (mod.lessons?.length) {
        navigate(`/members/courses/${courseId}/${mod.lessons[0].id}`, { replace: true });
        return;
      }
    }
  }, [course, lessonId, courseId, navigate]);

  // Mutations
  const completeMutation = useMutation({
    mutationFn: (id: string) => memberAreaService.updateLessonProgress(courseId!, id, { completed: true }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['member-course', courseId] });
      toast.success('Aula concluída!');
      if (nextLesson) navigate(`/members/courses/${courseId}/${nextLesson.id}`);
    },
  });

  const addCommentMutation = useMutation({
    mutationFn: (content: string) => memberAreaService.addLessonComment(courseId!, currentLesson!.id, content),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['member-course', courseId] });
      setNewComment('');
      toast.success('Comentário adicionado');
    },
  });

  const certificateMutation = useMutation({
    mutationFn: () => memberAreaService.getCertificate(courseId!),
    onSuccess: (data) => {
      if (data.success && data.data?.verification_url) {
        safeWindowOpen(data.data.verification_url);
        toast.success('Certificado gerado com sucesso!');
      } else {
        toast.success('Certificado gerado! Verifique seu email.');
      }
    },
    onError: () => {
      toast.error('Erro ao gerar certificado');
    },
  });

  const handleMarkComplete = () => { if (currentLesson) completeMutation.mutate(currentLesson.id); };
  const handleAddComment = () => { if (newComment.trim() && currentLesson) addCommentMutation.mutate(newComment.trim()); };
  const handleGetCertificate = () => { certificateMutation.mutate(); };

  // ─── Loading / Error ───────────────────────────────

  if (isLoading) {
    return (
      <div className="min-h-screen bg-gray-950 flex items-center justify-center">
        <div className="animate-spin rounded-full h-10 w-10 border-t-2 border-b-2 border-emerald-500" />
      </div>
    );
  }

  if (!course) {
    return (
      <div className="min-h-screen bg-gray-950 flex items-center justify-center">
        <div className="text-center space-y-3">
          <BookOpenIcon className="h-16 w-16 text-gray-600 mx-auto" />
          <h1 className="text-xl font-bold text-white">Curso não encontrado</h1>
          <Link to="/members/dashboard" className="text-emerald-400 hover:underline text-sm">Voltar ao dashboard</Link>
        </div>
      </div>
    );
  }

  // ─── Render ────────────────────────────────────────

  return (
    <div className="min-h-screen bg-gray-950 flex">
      {/* ═══ Main Content ═══ */}
      <div className={`flex-1 flex flex-col transition-all ${sidebarOpen ? 'lg:mr-80' : ''}`}>
        {/* Top Bar */}
        <div className="h-14 bg-gray-900/80 backdrop-blur-sm border-b border-gray-800 flex items-center px-4 gap-4 sticky top-0 z-20">
          <Link to="/members/dashboard" className="flex items-center gap-2 text-gray-400 hover:text-white transition-colors">
            <ArrowLeftIcon className="h-4 w-4" />
            <span className="hidden sm:inline text-sm">Voltar</span>
          </Link>

          <div className="flex-1 text-center">
            <h1 className="text-sm font-medium text-white truncate">{course.name}</h1>
            <div className="flex items-center justify-center gap-2 text-xs text-gray-500">
              <span>{completedLessons}/{totalLessons} aulas</span>
              <span>•</span>
              <span className="text-emerald-400 font-medium">{progressPercentage}%</span>
            </div>
          </div>

          <button onClick={() => setSidebarOpen(!sidebarOpen)}
            className="p-2 text-gray-400 hover:text-white rounded-lg hover:bg-gray-800 transition-colors">
            <ListBulletIcon className="h-5 w-5" />
          </button>
        </div>

        {/* Global Progress Bar */}
        <div className="h-1 bg-gray-800">
          <div className="h-full bg-gradient-to-r from-emerald-500 to-emerald-400 transition-all duration-500"
            style={{ width: `${progressPercentage}%` }} />
        </div>

        {/* Content Area */}
        <div className="flex-1 flex flex-col">
          <LessonContent lesson={currentLesson} />

          {/* Controls Bar */}
          <div className="bg-gray-900 border-t border-gray-800 px-4 py-3">
            <div className="max-w-5xl mx-auto flex items-center justify-between">
              {/* Navigation */}
              <div className="flex items-center gap-2">
                {prevLesson ? (
                  <Link to={`/members/courses/${courseId}/${prevLesson.id}`}
                    className="flex items-center gap-1.5 px-3 py-2 text-sm text-gray-400 hover:text-white rounded-lg hover:bg-gray-800 transition-colors">
                    <ChevronLeftIcon className="h-4 w-4" />
                    <span className="hidden sm:inline">Anterior</span>
                  </Link>
                ) : <div className="w-24" />}
                {nextLesson ? (
                  <Link to={`/members/courses/${courseId}/${nextLesson.id}`}
                    className="flex items-center gap-1.5 px-3 py-2 text-sm text-gray-400 hover:text-white rounded-lg hover:bg-gray-800 transition-colors">
                    <span className="hidden sm:inline">Próxima</span>
                    <ChevronRightIcon className="h-4 w-4" />
                  </Link>
                ) : <div className="w-24" />}
              </div>

              {/* Lesson Title */}
              <div className="text-center flex-1 px-4">
                <h2 className="text-white font-medium text-sm truncate">{currentLesson?.name}</h2>
                <div className="flex items-center justify-center gap-2 text-xs text-gray-500 mt-0.5">
                  {currentLesson?.content_type && (
                    <span className="capitalize">{currentLesson.content_type === 'video' ? 'Vídeo' : currentLesson.content_type === 'text' ? 'Texto' : currentLesson.content_type === 'quiz' ? 'Quiz' : currentLesson.content_type === 'download' ? 'Download' : 'Ao Vivo'}</span>
                  )}
                  {currentLesson?.duration_seconds ? (
                    <><span>•</span><span>{Math.floor(currentLesson.duration_seconds / 60)} min</span></>
                  ) : null}
                </div>
              </div>

              {/* Actions */}
              <div className="flex items-center gap-2">
                <button onClick={() => setShowComments(!showComments)}
                  className={`p-2 rounded-lg transition-colors ${showComments ? 'text-emerald-400 bg-emerald-400/10' : 'text-gray-400 hover:text-white hover:bg-gray-800'}`}
                  title="Comentários">
                  <ChatBubbleLeftIcon className="h-5 w-5" />
                  {currentLesson?.comments?.length ? (
                    <span className="sr-only">{currentLesson.comments.length}</span>
                  ) : null}
                </button>

                <button onClick={handleMarkComplete}
                  disabled={completeMutation.isPending || currentLesson?.progress?.completed}
                  className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all ${
                    currentLesson?.progress?.completed
                      ? 'bg-emerald-600/20 text-emerald-400 cursor-default'
                      : 'bg-emerald-600 hover:bg-emerald-500 text-white'
                  }`}>
                  <CheckCircleIcon className="h-5 w-5" />
                  <span className="hidden sm:inline">
                    {currentLesson?.progress?.completed ? 'Concluída' : 'Concluir'}
                  </span>
                </button>
              </div>
            </div>
          </div>

          {/* Lesson Description */}
          {currentLesson?.description && (
            <div className="bg-gray-900/50 px-4 py-4 border-t border-gray-800">
              <div className="max-w-5xl mx-auto">
                <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Sobre esta aula</h3>
                <p className="text-gray-400 text-sm leading-relaxed">{currentLesson.description}</p>
              </div>
            </div>
          )}

          {/* Comments Section */}
          {showComments && (
            <div className="bg-gray-900/50 px-4 py-5 border-t border-gray-800">
              <div className="max-w-5xl mx-auto">
                <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4">
                  Comentários ({currentLesson?.comments?.length || 0})
                </h3>

                {/* Add comment */}
                <div className="flex gap-3 mb-6">
                  <div className="h-8 w-8 rounded-full bg-emerald-600/20 flex items-center justify-center text-xs font-bold text-emerald-400 flex-shrink-0">
                    Eu
                  </div>
                  <div className="flex-1 flex gap-2">
                    <input type="text" value={newComment} onChange={(e) => setNewComment(e.target.value)}
                      placeholder="Escreva um comentário..."
                      className="flex-1 px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white text-sm placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                      onKeyDown={(e) => e.key === 'Enter' && handleAddComment()} />
                    <button onClick={handleAddComment}
                      disabled={!newComment.trim() || addCommentMutation.isPending}
                      className="px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-500 disabled:opacity-50 transition-colors">
                      Enviar
                    </button>
                  </div>
                </div>

                {/* Comments list */}
                <div className="space-y-4">
                  {currentLesson?.comments?.map((comment: any) => (
                    <div key={comment.id} className="flex gap-3">
                      <div className="h-8 w-8 rounded-full bg-gray-700 flex items-center justify-center text-xs font-bold text-gray-300 flex-shrink-0">
                        {comment.member?.name?.charAt(0) || '?'}
                      </div>
                      <div className="flex-1">
                        <div className="flex items-center gap-2">
                          <span className="text-sm font-medium text-white">{comment.member?.name || 'Anônimo'}</span>
                          <span className="text-xs text-gray-600">
                            {new Date(comment.created_at).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' })}
                          </span>
                        </div>
                        <p className="text-sm text-gray-400 mt-1">{comment.content}</p>
                      </div>
                    </div>
                  ))}
                  {(!currentLesson?.comments || currentLesson.comments.length === 0) && (
                    <p className="text-center text-gray-600 py-6 text-sm">
                      Nenhum comentário ainda. Seja o primeiro a comentar!
                    </p>
                  )}
                </div>
              </div>
            </div>
          )}

          {/* Course Completed Banner */}
          {progressPercentage === 100 && (
            <div className="bg-gradient-to-r from-emerald-900/30 to-emerald-800/20 border-t border-emerald-800/50 px-4 py-6">
              <div className="max-w-5xl mx-auto text-center">
                <TrophyIcon className="h-12 w-12 text-amber-400 mx-auto mb-2" />
                <h3 className="text-lg font-bold text-white mb-1">Parabéns! Você concluiu o curso!</h3>
                <p className="text-sm text-gray-400 mb-3">Todas as {totalLessons} aulas foram concluídas</p>
                <div className="flex items-center justify-center gap-3">
                  <button onClick={handleGetCertificate}
                    disabled={certificateMutation.isPending}
                    className="inline-flex items-center gap-2 px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-500 text-sm font-medium transition-colors disabled:opacity-50">
                    <TrophyIcon className="h-4 w-4" />
                    {certificateMutation.isPending ? 'Gerando...' : 'Obter Certificado'}
                  </button>
                  <Link to="/members/dashboard"
                    className="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-500 text-sm font-medium transition-colors">
                    Voltar ao Dashboard
                  </Link>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* ═══ Sidebar ═══ */}
      <div className={`fixed right-0 top-0 bottom-0 w-80 bg-gray-900 border-l border-gray-800 transform transition-transform duration-300 ${
        sidebarOpen ? 'translate-x-0' : 'translate-x-full'
      } z-40 flex flex-col`}>
        {/* Sidebar Header */}
        <div className="h-14 border-b border-gray-800 flex items-center justify-between px-4 flex-shrink-0">
          <div>
            <h2 className="text-white font-medium text-sm">Conteúdo</h2>
            <p className="text-xs text-gray-500">{completedLessons}/{totalLessons} concluídas</p>
          </div>
          <button onClick={() => setSidebarOpen(false)}
            className="p-1.5 text-gray-400 hover:text-white rounded-lg hover:bg-gray-800 lg:hidden">
            <XMarkIcon className="h-5 w-5" />
          </button>
        </div>

        {/* Sidebar Progress */}
        <div className="px-4 py-3 border-b border-gray-800 flex-shrink-0">
          <div className="flex items-center justify-between text-xs text-gray-500 mb-1.5">
            <span>Progresso</span>
            <span className="text-emerald-400 font-semibold">{progressPercentage}%</span>
          </div>
          <div className="h-2 bg-gray-800 rounded-full overflow-hidden">
            <div className="h-full bg-gradient-to-r from-emerald-500 to-emerald-400 rounded-full transition-all duration-500"
              style={{ width: `${progressPercentage}%` }} />
          </div>
        </div>

        {/* Modules */}
        <div className="overflow-y-auto flex-1">
          {course.modules?.map((module: CourseModule, moduleIndex: number) => (
            <ModuleAccordion key={module.id} module={module} moduleIndex={moduleIndex}
              courseId={courseId!} currentLessonId={currentLesson?.id} />
          ))}
        </div>
      </div>

      {/* Mobile overlay */}
      {sidebarOpen && (
        <div className="fixed inset-0 bg-black/60 z-30 lg:hidden" onClick={() => setSidebarOpen(false)} />
      )}
    </div>
  );
}

// ─── Lesson Content Renderer ─────────────────────────

function LessonContent({ lesson }: { lesson: Lesson | null }) {
  if (!lesson) {
    return (
      <div className="flex-1 flex items-center justify-center bg-gray-950">
        <BookOpenIcon className="h-16 w-16 text-gray-700" />
      </div>
    );
  }

  switch (lesson.content_type) {
    case 'video':
      return (
        <div className="relative bg-black aspect-video max-h-[70vh]">
          {lesson.embed_url ? (
            <iframe src={lesson.embed_url} className="w-full h-full" allowFullScreen
              allow="autoplay; fullscreen; picture-in-picture; encrypted-media" />
          ) : (
            <div className="w-full h-full flex items-center justify-center">
              <div className="text-center">
                <PlayIcon className="h-16 w-16 text-gray-600 mx-auto mb-2" />
                <p className="text-gray-500 text-sm">Vídeo não disponível</p>
              </div>
            </div>
          )}
        </div>
      );

    case 'text':
      return (
        <div className="flex-1 bg-gray-900 overflow-y-auto">
          <div className="max-w-3xl mx-auto px-6 py-8">
            <div className="prose prose-invert prose-emerald max-w-none prose-headings:text-white prose-p:text-gray-300 prose-a:text-emerald-400 prose-strong:text-white prose-li:text-gray-300"
              dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(lesson.content || '<p class="text-gray-500">Conteúdo não disponível</p>') }} />
          </div>
        </div>
      );

    case 'quiz':
      return <QuizContent lesson={lesson} />;

    case 'download':
      return (
        <div className="flex-1 bg-gray-900 flex items-center justify-center">
          <div className="text-center p-8 max-w-md">
            <div className="h-20 w-20 rounded-2xl bg-emerald-600/10 flex items-center justify-center mx-auto mb-4">
              <ArrowDownTrayIcon className="h-10 w-10 text-emerald-400" />
            </div>
            <h3 className="text-xl font-bold text-white mb-2">{lesson.name}</h3>
            {lesson.content && <p className="text-gray-400 text-sm mb-6">{lesson.content}</p>}
            {lesson.video_url && (
              <a href={lesson.video_url} target="_blank" rel="noopener noreferrer"
                className="inline-flex items-center gap-2 px-6 py-3 bg-emerald-600 text-white rounded-xl hover:bg-emerald-500 font-medium transition-colors">
                <ArrowDownTrayIcon className="h-5 w-5" />
                Baixar Material
              </a>
            )}
          </div>
        </div>
      );

    case 'live':
      return (
        <div className="flex-1 bg-gray-900 flex items-center justify-center">
          <div className="text-center p-8 max-w-md">
            <div className="h-20 w-20 rounded-2xl bg-red-600/10 flex items-center justify-center mx-auto mb-4 relative">
              <SignalIcon className="h-10 w-10 text-red-400" />
              <span className="absolute -top-1 -right-1 h-4 w-4 bg-red-500 rounded-full animate-pulse" />
            </div>
            <h3 className="text-xl font-bold text-white mb-2">{lesson.name}</h3>
            {lesson.content && <p className="text-gray-400 text-sm mb-6 whitespace-pre-line">{lesson.content}</p>}
            {lesson.video_url && (
              <a href={lesson.video_url} target="_blank" rel="noopener noreferrer"
                className="inline-flex items-center gap-2 px-6 py-3 bg-red-600 text-white rounded-xl hover:bg-red-500 font-medium transition-colors">
                <SignalIcon className="h-5 w-5" />
                Acessar Transmissão
              </a>
            )}
          </div>
        </div>
      );

    default:
      return (
        <div className="flex-1 flex items-center justify-center bg-gray-950">
          <BookOpenIcon className="h-16 w-16 text-gray-700" />
        </div>
      );
  }
}

// ─── Quiz Content ────────────────────────────────────

function QuizContent({ lesson }: { lesson: Lesson }) {
  const [answers, setAnswers] = useState<Record<number, number>>({});
  const [submitted, setSubmitted] = useState(false);

  const questions = useMemo(() => {
    try { return JSON.parse(lesson.content || '[]'); }
    catch { return []; }
  }, [lesson.content]);

  const score = useMemo(() => {
    if (!submitted) return 0;
    return questions.reduce((s: number, q: any, i: number) => s + (answers[i] === q.correct ? 1 : 0), 0);
  }, [submitted, answers, questions]);

  if (questions.length === 0) {
    return (
      <div className="flex-1 bg-gray-900 flex items-center justify-center">
        <div className="text-center">
          <QuestionMarkCircleIcon className="h-16 w-16 text-amber-500/30 mx-auto mb-3" />
          <p className="text-gray-500">Quiz sem perguntas configuradas</p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex-1 bg-gray-900 overflow-y-auto">
      <div className="max-w-2xl mx-auto px-6 py-8 space-y-6">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-bold text-white">Quiz - {lesson.name}</h2>
          <span className="text-sm text-gray-500">{questions.length} perguntas</span>
        </div>

        {questions.map((q: any, qi: number) => (
          <div key={qi} className="bg-gray-800/50 rounded-xl p-5 border border-gray-700/50">
            <p className="text-white font-medium mb-3">{qi + 1}. {q.question}</p>
            <div className="space-y-2">
              {q.options?.map((opt: string, oi: number) => {
                const selected = answers[qi] === oi;
                const isCorrect = submitted && oi === q.correct;
                const isWrong = submitted && selected && oi !== q.correct;
                return (
                  <button key={oi} onClick={() => !submitted && setAnswers({ ...answers, [qi]: oi })}
                    disabled={submitted}
                    className={`w-full text-left px-4 py-3 rounded-lg border text-sm transition-all ${
                      isCorrect ? 'border-emerald-500 bg-emerald-500/10 text-emerald-400' :
                      isWrong ? 'border-red-500 bg-red-500/10 text-red-400' :
                      selected ? 'border-amber-500 bg-amber-500/10 text-amber-400' :
                      'border-gray-700 hover:border-gray-600 text-gray-300'
                    }`}>
                    <span className="font-medium mr-2">{String.fromCharCode(65 + oi)}.</span>
                    {opt}
                  </button>
                );
              })}
            </div>
          </div>
        ))}

        {!submitted ? (
          <button onClick={() => setSubmitted(true)}
            disabled={Object.keys(answers).length < questions.length}
            className="w-full py-3 bg-amber-600 text-white rounded-xl font-medium hover:bg-amber-500 disabled:opacity-50 transition-colors">
            Verificar Respostas
          </button>
        ) : (
          <div className="text-center py-4">
            <p className="text-2xl font-bold text-white">{score}/{questions.length}</p>
            <p className="text-sm text-gray-400 mt-1">
              {score === questions.length ? 'Parabéns! Acertou tudo!' :
               score >= questions.length * 0.7 ? 'Bom trabalho!' : 'Continue estudando!'}
            </p>
            <button onClick={() => { setAnswers({}); setSubmitted(false); }}
              className="mt-3 px-4 py-2 text-sm text-amber-400 hover:bg-amber-400/10 rounded-lg transition-colors">
              Tentar novamente
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

// ─── Module Accordion ────────────────────────────────

function ModuleAccordion({ module, moduleIndex, courseId, currentLessonId }: {
  module: CourseModule;
  moduleIndex: number;
  courseId: string;
  currentLessonId?: string;
}) {
  const [isOpen, setIsOpen] = useState(() =>
    module.lessons?.some((l: Lesson) => l.id === currentLessonId) || moduleIndex === 0
  );

  const lessons = module.lessons || [];
  const completedCount = lessons.filter((l: Lesson) => l.progress?.completed).length;
  const moduleProgress = lessons.length > 0 ? Math.round((completedCount / lessons.length) * 100) : 0;

  return (
    <div className="border-b border-gray-800/50">
      <button onClick={() => setIsOpen(!isOpen)}
        className="w-full px-4 py-3 flex items-center justify-between hover:bg-gray-800/30 transition-colors">
        <div className="flex-1 text-left">
          <h3 className="text-sm font-medium text-white">
            {moduleIndex + 1}. {module.name}
          </h3>
          <div className="flex items-center gap-2 mt-1">
            <div className="flex-1 h-1 bg-gray-800 rounded-full max-w-[80px]">
              <div className="h-full bg-emerald-500 rounded-full transition-all" style={{ width: `${moduleProgress}%` }} />
            </div>
            <span className="text-[10px] text-gray-600">{completedCount}/{lessons.length}</span>
          </div>
        </div>
        <ChevronRightIcon className={`h-4 w-4 text-gray-600 transition-transform ${isOpen ? 'rotate-90' : ''}`} />
      </button>

      {isOpen && (
        <div className="pb-1">
          {lessons.map((lesson: Lesson, li: number) => {
            const TypeIcon = LESSON_ICONS[lesson.content_type] || PlayIcon;
            const isCurrent = lesson.id === currentLessonId;
            const isCompleted = lesson.progress?.completed;

            return (
              <Link key={lesson.id} to={`/members/courses/${courseId}/${lesson.id}`}
                className={`flex items-center gap-3 px-4 py-2.5 transition-colors ${
                  isCurrent ? 'bg-emerald-500/10 border-r-2 border-emerald-500' : 'hover:bg-gray-800/40'
                }`}>
                <div className="flex-shrink-0 relative">
                  {isCompleted ? (
                    <CheckCircleIcon className="h-5 w-5 text-emerald-500" />
                  ) : isCurrent ? (
                    <div className="h-5 w-5 rounded-full bg-emerald-500 flex items-center justify-center">
                      <PlayIcon className="h-3 w-3 text-white" />
                    </div>
                  ) : (
                    <TypeIcon className="h-5 w-5 text-gray-600" />
                  )}
                </div>

                <div className="flex-1 min-w-0">
                  <p className={`text-sm truncate ${isCurrent ? 'text-emerald-400 font-medium' : isCompleted ? 'text-gray-400' : 'text-gray-400'}`}>
                    {moduleIndex + 1}.{li + 1} {lesson.name}
                  </p>
                  <div className="flex items-center gap-2 text-[10px] text-gray-600 mt-0.5">
                    {lesson.duration_seconds > 0 && (
                      <span className="flex items-center gap-0.5">
                        <ClockIcon className="h-2.5 w-2.5" />
                        {Math.floor(lesson.duration_seconds / 60)}:{String(lesson.duration_seconds % 60).padStart(2, '0')}
                      </span>
                    )}
                    {lesson.is_free && <span className="text-emerald-500">Grátis</span>}
                  </div>
                </div>
              </Link>
            );
          })}
        </div>
      )}
    </div>
  );
}
