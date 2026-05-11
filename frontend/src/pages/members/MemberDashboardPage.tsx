import { useState, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import {
  PlayIcon,
  CheckCircleIcon,
  ChevronRightIcon,
  BookOpenIcon,
  MagnifyingGlassIcon,
  UserCircleIcon,
  ArrowRightOnRectangleIcon,
  Cog6ToothIcon,
} from '@heroicons/react/24/solid';
import { memberAreaService, Enrollment } from '@/services/api/memberArea.service';

interface MemberData {
  id: string;
  name: string;
  email: string;
  avatar_url?: string;
}

export default function MemberDashboardPage() {
  const navigate = useNavigate();
  const [member, setMember] = useState<MemberData | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [showSearch, setShowSearch] = useState(false);
  const [showProfileMenu, setShowProfileMenu] = useState(false);

  // Load member data from localStorage
  useEffect(() => {
    const memberData = localStorage.getItem('member_data');
    if (memberData) {
      try {
        setMember(JSON.parse(memberData));
      } catch {
        localStorage.removeItem('member_data');
        localStorage.removeItem('member_token');
        navigate('/members/login');
      }
    } else {
      navigate('/members/login');
    }
  }, [navigate]);

  // Fetch member's courses
  const { data: coursesData, isLoading } = useQuery({
    queryKey: ['member-courses'],
    queryFn: () => memberAreaService.getMemberCourses(),
    enabled: !!member,
  });

  const enrollments = coursesData?.data || [];
  
  // Filter courses
  const filteredCourses = enrollments.filter((enrollment: Enrollment) =>
    enrollment.course?.name?.toLowerCase().includes(searchQuery.toLowerCase())
  );

  // Separate in-progress and completed
  const inProgress = filteredCourses.filter((e: Enrollment) => (e.progress_percentage || 0) < 100);
  const completed = filteredCourses.filter((e: Enrollment) => (e.progress_percentage || 0) >= 100);
  const notStarted = filteredCourses.filter((e: Enrollment) => (e.progress_percentage || 0) === 0);

  const handleLogout = () => {
    localStorage.removeItem('member_token');
    localStorage.removeItem('member_data');
    navigate('/members/login');
  };

  if (isLoading) {
    return (
      <div className="min-h-screen bg-gray-900 flex items-center justify-center">
        <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-emerald-500" />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-gray-900">
      {/* Header */}
      <header className="fixed top-0 left-0 right-0 z-50 bg-gradient-to-b from-gray-900 via-gray-900/95 to-transparent">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex items-center justify-between h-16">
            {/* Logo */}
            <div className="flex items-center gap-8">
              <Link to="/members/dashboard" className="text-2xl font-bold text-white">
                <span className="bg-gradient-to-r from-emerald-400 to-green-500 bg-clip-text text-transparent">
                  MemberArea
                </span>
              </Link>
              <nav className="hidden md:flex items-center gap-6">
                <Link to="/members/dashboard" className="text-white font-medium">
                  Início
                </Link>
                <Link to="/members/courses" className="text-tx-muted hover:text-white transition-colors">
                  Meus Cursos
                </Link>
              </nav>
            </div>

            {/* Search & Profile */}
            <div className="flex items-center gap-4">
              {/* Search */}
              <div className="relative">
                {showSearch ? (
                  <div className="flex items-center bg-gray-800 border border-gray-600 rounded-lg overflow-hidden">
                    <MagnifyingGlassIcon className="h-5 w-5 text-tx-muted ml-3" />
                    <input
                      type="text"
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                      placeholder="Buscar cursos..."
                      className="w-48 px-3 py-2 bg-transparent text-white placeholder-gray-400 focus:outline-none"
                      autoFocus
                      onBlur={() => !searchQuery && setShowSearch(false)}
                    />
                  </div>
                ) : (
                  <button
                    onClick={() => setShowSearch(true)}
                    className="p-2 text-tx-muted hover:text-white transition-colors"
                  >
                    <MagnifyingGlassIcon className="h-6 w-6" />
                  </button>
                )}
              </div>

              {/* Profile Menu */}
              <div className="relative">
                <button
                  onClick={() => setShowProfileMenu(!showProfileMenu)}
                  className="flex items-center gap-2"
                >
                  {member?.avatar_url ? (
                    <img
                      src={member.avatar_url}
                      alt={member.name}
                      className="h-8 w-8 rounded object-cover"
                    />
                  ) : (
                    <UserCircleIcon className="h-8 w-8 text-tx-muted" />
                  )}
                </button>

                {showProfileMenu && (
                  <div className="absolute right-0 mt-2 w-48 bg-gray-800 border border-gray-700 rounded-lg shadow-xl py-1">
                    <div className="px-4 py-2 border-b border-gray-700">
                      <p className="text-sm font-medium text-white">{member?.name}</p>
                      <p className="text-xs text-tx-muted">{member?.email}</p>
                    </div>
                    <Link
                      to="/members/profile"
                      className="w-full px-4 py-2 text-left text-sm text-tx-muted hover:bg-gray-700 flex items-center gap-2"
                    >
                      <Cog6ToothIcon className="h-4 w-4" />
                      Meu Perfil
                    </Link>
                    <button
                      onClick={handleLogout}
                      className="w-full px-4 py-2 text-left text-sm text-tx-muted hover:bg-gray-700 flex items-center gap-2"
                    >
                      <ArrowRightOnRectangleIcon className="h-4 w-4" />
                      Sair
                    </button>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      </header>

      {/* Main Content */}
      <main className="pt-20 pb-12">
        {/* Hero Section - Last watched or featured course */}
        {inProgress.length > 0 && (
          <section className="relative mb-8">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
              <HeroCourse enrollment={inProgress[0]} />
            </div>
          </section>
        )}

        {/* Continue Watching */}
        {inProgress.length > 0 && (
          <CourseRow
            title="Continue Assistindo"
            enrollments={inProgress}
            showProgress
          />
        )}

        {/* Not Started */}
        {notStarted.length > 0 && (
          <CourseRow
            title="Começar a Assistir"
            enrollments={notStarted}
          />
        )}

        {/* All Courses */}
        {filteredCourses.length > 0 && (
          <CourseRow
            title="Meus Cursos"
            enrollments={filteredCourses}
            showProgress
          />
        )}

        {/* Completed */}
        {completed.length > 0 && (
          <CourseRow
            title="Concluídos"
            enrollments={completed}
            badge="completed"
          />
        )}

        {/* Empty State */}
        {enrollments.length === 0 && (
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-20">
            <div className="text-center py-20">
              <BookOpenIcon className="h-16 w-16 text-tx-secondary mx-auto mb-4" />
              <h2 className="text-2xl font-semibold text-white mb-2">
                Nenhum curso disponível
              </h2>
              <p className="text-tx-muted">
                Você ainda não possui acesso a nenhum curso.
              </p>
            </div>
          </div>
        )}
      </main>
    </div>
  );
}

// Hero Course Component
function HeroCourse({ enrollment }: { enrollment: Enrollment }) {
  const course = enrollment.course;
  if (!course) return null;

  return (
    <div className="relative h-[500px] rounded-2xl overflow-hidden">
      {/* Background Image */}
      <div className="absolute inset-0">
        {course.cover_url || course.thumbnail_url ? (
          <img
            src={course.cover_url || course.thumbnail_url}
            alt={course.name}
            className="w-full h-full object-cover"
          />
        ) : (
          <div className="w-full h-full bg-gradient-to-br from-emerald-900 to-gray-900" />
        )}
        <div className="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/60 to-transparent" />
        <div className="absolute inset-0 bg-gradient-to-r from-gray-900 via-transparent to-transparent" />
      </div>

      {/* Content */}
      <div className="absolute bottom-0 left-0 right-0 p-8">
        <div className="max-w-2xl">
          <h1 className="text-4xl md:text-5xl font-bold text-white mb-4">
            {course.name}
          </h1>
          {course.short_description && (
            <p className="text-lg text-tx-muted mb-6 line-clamp-2">
              {course.short_description}
            </p>
          )}
          
          {/* Progress */}
          <div className="mb-6">
            <div className="flex items-center gap-2 mb-2">
              <span className="text-sm text-tx-muted">
                {enrollment.progress_percentage || 0}% concluído
              </span>
            </div>
            <div className="w-64 h-1 bg-gray-700 rounded-full overflow-hidden">
              <div
                className="h-full bg-accent-subtle0 rounded-full transition-all"
                style={{ width: `${enrollment.progress_percentage || 0}%` }}
              />
            </div>
          </div>

          <div className="flex items-center gap-4">
            <Link
              to={`/members/courses/${course.id}`}
              className="flex items-center gap-2 px-6 py-3 bg-surface-card text-tx-primary font-semibold rounded-element hover:bg-gray-200 transition-colors"
            >
              <PlayIcon className="h-5 w-5" />
              {enrollment.progress_percentage ? 'Continuar' : 'Assistir'}
            </Link>
            <Link
              to={`/members/courses/${course.id}/info`}
              className="flex items-center gap-2 px-6 py-3 bg-gray-700/80 text-white font-medium rounded-element hover:bg-gray-600 transition-colors"
            >
              Mais informações
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}

// Course Row Component
function CourseRow({
  title,
  enrollments,
  showProgress = false,
  badge,
}: {
  title: string;
  enrollments: Enrollment[];
  showProgress?: boolean;
  badge?: 'completed' | 'new';
}) {
  return (
    <section className="mb-8">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-xl font-semibold text-white">{title}</h2>
          {enrollments.length > 5 && (
            <Link
              to="/members/courses"
              className="text-sm text-emerald-400 hover:text-emerald-300 flex items-center gap-1"
            >
              Ver todos
              <ChevronRightIcon className="h-4 w-4" />
            </Link>
          )}
        </div>

        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
          {enrollments.slice(0, 6).map((enrollment) => (
            <CourseCard
              key={enrollment.id}
              enrollment={enrollment}
              showProgress={showProgress}
              badge={badge}
            />
          ))}
        </div>
      </div>
    </section>
  );
}

// Course Card Component - Netflix style
function CourseCard({
  enrollment,
  showProgress = false,
  badge,
}: {
  enrollment: Enrollment;
  showProgress?: boolean;
  badge?: 'completed' | 'new';
}) {
  const course = enrollment.course;
  if (!course) return null;

  return (
    <Link
      to={`/members/courses/${course.id}`}
      className="group relative rounded-lg overflow-hidden bg-gray-800 aspect-[2/3] transition-transform hover:scale-105 hover:z-10"
    >
      {/* Thumbnail */}
      {course.thumbnail_url ? (
        <img
          src={course.thumbnail_url}
          alt={course.name}
          className="w-full h-full object-cover"
        />
      ) : (
        <div className="w-full h-full bg-gradient-to-br from-emerald-800 to-gray-800 flex items-center justify-center">
          <BookOpenIcon className="h-12 w-12 text-tx-secondary" />
        </div>
      )}

      {/* Overlay */}
      <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity">
        <div className="absolute bottom-0 left-0 right-0 p-3">
          <h3 className="text-sm font-medium text-white line-clamp-2">
            {course.name}
          </h3>
          {course.instructor_name && (
            <p className="text-xs text-tx-muted mt-1">{course.instructor_name}</p>
          )}
        </div>
      </div>

      {/* Progress bar */}
      {showProgress && enrollment.progress_percentage !== undefined && enrollment.progress_percentage > 0 && (
        <div className="absolute bottom-0 left-0 right-0 h-1 bg-gray-700">
          <div
            className="h-full bg-accent-subtle0"
            style={{ width: `${enrollment.progress_percentage}%` }}
          />
        </div>
      )}

      {/* Badge */}
      {badge === 'completed' && (
        <div className="absolute top-2 right-2 bg-status-success-subtle0 rounded-full p-1">
          <CheckCircleIcon className="h-4 w-4 text-white" />
        </div>
      )}

      {/* Play icon on hover */}
      <div className="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
        <div className="w-12 h-12 rounded-full bg-surface-card/20 backdrop-blur-sm flex items-center justify-center">
          <PlayIcon className="h-6 w-6 text-white ml-1" />
        </div>
      </div>
    </Link>
  );
}
