import { api } from '@/lib/api';

export interface MemberArea {
  id: string;
  account_id: string;
  name: string;
  description?: string;
  logo_url?: string;
  favicon_url?: string;
  primary_color: string;
  secondary_color: string;
  accent_color: string;
  background_color: string;
  text_color: string;
  font_family: string;
  theme_mode: 'dark' | 'light';
  layout_style: 'netflix' | 'traditional' | 'minimal' | 'grid' | 'list';
  banner_url?: string;
  welcome_message?: string;
  welcome_video_url?: string;
  subdomain?: string;
  custom_domain?: string;
  domain_verified: boolean;
  ssl_enabled: boolean;
  featured_courses: string[];
  home_sections: any[];
  custom_css?: any;
  settings: any;
  is_active: boolean;
}

export interface Course {
  id: string;
  account_id: string;
  product_id?: string;
  name: string;
  slug: string;
  custom_domain?: string;
  description?: string;
  short_description?: string;
  thumbnail_url?: string;
  cover_url?: string;
  trailer_url?: string;
  instructor_name?: string;
  instructor_avatar?: string;
  instructor_bio?: string;
  duration_minutes: number;
  level: 'beginner' | 'intermediate' | 'advanced' | 'all';
  tags: string[];
  requirements: string[];
  objectives: string[];
  sort_order: number;
  is_featured: boolean;
  is_published: boolean;
  published_at?: string;
  modules_count?: number;
  enrollments_count?: number;
  modules?: CourseModule[];
}

export interface CourseModule {
  id: string;
  course_id: string;
  name: string;
  description?: string;
  thumbnail_url?: string;
  sort_order: number;
  is_published: boolean;
  is_free: boolean;
  lessons_count?: number;
  lessons?: Lesson[];
}

export interface Lesson {
  id: string;
  module_id: string;
  name: string;
  description?: string;
  thumbnail_url?: string;
  content_type: 'video' | 'text' | 'quiz' | 'download' | 'live';
  video_url?: string;
  video_provider: 'youtube' | 'vimeo' | 'bunny' | 'custom';
  video_id?: string;
  embed_url?: string;
  duration_seconds: number;
  content?: string;
  attachments: any[];
  resources: any[];
  sort_order: number;
  is_published: boolean;
  is_free: boolean;
  is_downloadable: boolean;
  progress?: {
    watched_seconds: number;
    progress_percent: number;
    completed: boolean;
    is_completed?: boolean;
  };
  comments?: LessonComment[];
}

export interface LessonComment {
  id: string;
  lesson_id: string;
  member_id: string;
  content: string;
  created_at: string;
  member?: {
    id: string;
    name: string;
    avatar_url?: string;
  };
}

export interface Enrollment {
  id: string;
  user_id: string;
  course_id: string;
  charge_id?: string;
  status: 'active' | 'expired' | 'cancelled' | 'pending';
  access_type: 'lifetime' | 'subscription' | 'limited';
  enrolled_at?: string;
  expires_at?: string;
  last_accessed_at?: string;
  progress_percent: number;
  progress_percentage?: number; // Alias for progress_percent
  completed_at?: string;
  user?: {
    id: string;
    name: string;
    email: string;
  };
  course?: Course;
}

export interface MemberAreaDashboard {
  total_courses: number;
  total_lessons: number;
  total_members: number;
  total_enrollments: number;
  active_enrollments: number;
  completion_rate: number;
  recent_enrollments: Enrollment[];
  popular_courses: Course[];
}

export const memberAreaService = {
  // Member Area Settings
  async getSettings() {
    const response = await api.get('/member-area');
    return response.data;
  },

  async updateSettings(data: Partial<MemberArea>) {
    const response = await api.put('/member-area', data);
    return response.data;
  },

  async getDashboard(): Promise<{ data: MemberAreaDashboard }> {
    const response = await api.get('/member-area/dashboard');
    return response.data;
  },

  async checkSubdomain(subdomain: string) {
    const response = await api.post('/member-area/check-subdomain', { subdomain });
    return response.data;
  },

  async verifyDomain() {
    const response = await api.post('/member-area/verify-domain');
    return response.data;
  },

  // Domain verification for member area
  async checkDomainVerification() {
    const response = await api.get('/member-area/domain/check');
    return response.data;
  },

  async generateDomainToken() {
    const response = await api.post('/member-area/domain/generate-token');
    return response.data;
  },

  // Courses
  async listCourses(params?: { search?: string; status?: string; per_page?: number; page?: number }) {
    const response = await api.get('/member-area/courses', { params });
    return response.data;
  },

  async createCourse(data: Partial<Course>) {
    const response = await api.post('/member-area/courses', data);
    return response.data;
  },

  async getCourse(courseId: string) {
    const response = await api.get(`/member-area/courses/${courseId}`);
    return response.data;
  },

  async updateCourse(courseId: string, data: Partial<Course>) {
    const response = await api.put(`/member-area/courses/${courseId}`, data);
    return response.data;
  },

  async deleteCourse(courseId: string) {
    const response = await api.delete(`/member-area/courses/${courseId}`);
    return response.data;
  },

  async duplicateCourse(courseId: string) {
    const response = await api.post(`/member-area/courses/${courseId}/duplicate`);
    return response.data;
  },

  async reorderCourses(courses: { id: string; sort_order: number }[]) {
    const response = await api.post('/member-area/courses/reorder', { courses });
    return response.data;
  },

  async getCourseStudents(courseId: string, params?: { per_page?: number; page?: number }) {
    const response = await api.get(`/member-area/courses/${courseId}/students`, { params });
    return response.data;
  },

  // Modules
  async listModules(courseId: string) {
    const response = await api.get(`/member-area/courses/${courseId}/modules`);
    return response.data;
  },

  async createModule(courseId: string, data: Partial<CourseModule>) {
    const response = await api.post(`/member-area/courses/${courseId}/modules`, data);
    return response.data;
  },

  async getModule(courseId: string, moduleId: string) {
    const response = await api.get(`/member-area/courses/${courseId}/modules/${moduleId}`);
    return response.data;
  },

  async updateModule(courseId: string, moduleId: string, data: Partial<CourseModule>) {
    const response = await api.put(`/member-area/courses/${courseId}/modules/${moduleId}`, data);
    return response.data;
  },

  async deleteModule(courseId: string, moduleId: string) {
    const response = await api.delete(`/member-area/courses/${courseId}/modules/${moduleId}`);
    return response.data;
  },

  async reorderModules(courseId: string, modules: { id: string; sort_order: number }[]) {
    const response = await api.post(`/member-area/courses/${courseId}/modules/reorder`, { modules });
    return response.data;
  },

  // Lessons
  async listLessons(courseId: string, moduleId: string) {
    const response = await api.get(`/member-area/courses/${courseId}/modules/${moduleId}/lessons`);
    return response.data;
  },

  async createLesson(courseId: string, moduleId: string, data: Partial<Lesson>) {
    const response = await api.post(`/member-area/courses/${courseId}/modules/${moduleId}/lessons`, data);
    return response.data;
  },

  async getLesson(courseId: string, moduleId: string, lessonId: string) {
    const response = await api.get(`/member-area/courses/${courseId}/modules/${moduleId}/lessons/${lessonId}`);
    return response.data;
  },

  async updateLesson(courseId: string, moduleId: string, lessonId: string, data: Partial<Lesson>) {
    const response = await api.put(`/member-area/courses/${courseId}/modules/${moduleId}/lessons/${lessonId}`, data);
    return response.data;
  },

  async deleteLesson(courseId: string, moduleId: string, lessonId: string) {
    const response = await api.delete(`/member-area/courses/${courseId}/modules/${moduleId}/lessons/${lessonId}`);
    return response.data;
  },

  async reorderLessons(courseId: string, moduleId: string, lessons: { id: string; sort_order: number }[]) {
    const response = await api.post(`/member-area/courses/${courseId}/modules/${moduleId}/lessons/reorder`, { lessons });
    return response.data;
  },

  async moveLesson(courseId: string, moduleId: string, lessonId: string, targetModuleId: string) {
    const response = await api.post(`/member-area/courses/${courseId}/modules/${moduleId}/lessons/${lessonId}/move`, {
      target_module_id: targetModuleId,
    });
    return response.data;
  },

  // Enrollments
  async listEnrollments(params?: { course_id?: string; status?: string; search?: string; per_page?: number; page?: number }) {
    const response = await api.get('/member-area/enrollments', { params });
    return response.data;
  },

  async createEnrollment(data: { course_id: string; user_email: string; user_name?: string; access_type?: string; expires_at?: string }) {
    const response = await api.post('/member-area/enrollments', data);
    return response.data;
  },

  async getEnrollment(enrollmentId: string) {
    const response = await api.get(`/member-area/enrollments/${enrollmentId}`);
    return response.data;
  },

  async updateEnrollment(enrollmentId: string, data: Partial<Enrollment>) {
    const response = await api.put(`/member-area/enrollments/${enrollmentId}`, data);
    return response.data;
  },

  async cancelEnrollment(enrollmentId: string) {
    const response = await api.delete(`/member-area/enrollments/${enrollmentId}`);
    return response.data;
  },

  async extendEnrollment(enrollmentId: string, days: number) {
    const response = await api.post(`/member-area/enrollments/${enrollmentId}/extend`, { days });
    return response.data;
  },

  async resetEnrollmentProgress(enrollmentId: string) {
    const response = await api.post(`/member-area/enrollments/${enrollmentId}/reset-progress`);
    return response.data;
  },

  async resetMemberPassword(enrollmentId: string) {
    const response = await api.post(`/member-area/enrollments/${enrollmentId}/reset-password`);
    return response.data;
  },

  // ==========================================
  // Member-facing API (student/consumer side)
  // ==========================================

  // Member Login
  async memberLogin(data: { email: string; password: string }) {
    const response = await api.post('/members/login', data);
    return response.data;
  },

  // Get member's enrolled courses
  async getMemberCourses() {
    const token = localStorage.getItem('member_token');
    const response = await api.get('/members/courses', {
      headers: { Authorization: `Bearer ${token}` },
    });
    return response.data;
  },

  // Get a specific course with modules and lessons
  async getMemberCourse(courseId: string) {
    const token = localStorage.getItem('member_token');
    const response = await api.get(`/members/courses/${courseId}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    return response.data;
  },

  // Update lesson progress
  async updateLessonProgress(courseId: string, lessonId: string, data: { watched_seconds?: number; completed?: boolean }) {
    const token = localStorage.getItem('member_token');
    const response = await api.post(`/members/courses/${courseId}/lessons/${lessonId}/progress`, data, {
      headers: { Authorization: `Bearer ${token}` },
    });
    return response.data;
  },

  // Add comment to a lesson
  async addLessonComment(courseId: string, lessonId: string, content: string) {
    const token = localStorage.getItem('member_token');
    const response = await api.post(`/members/courses/${courseId}/lessons/${lessonId}/comments`, { content }, {
      headers: { Authorization: `Bearer ${token}` },
    });
    return response.data;
  },

  // Get lesson comments
  async getLessonComments(courseId: string, lessonId: string) {
    const token = localStorage.getItem('member_token');
    const response = await api.get(`/members/courses/${courseId}/lessons/${lessonId}/comments`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    return response.data;
  },

  // Get member profile
  async getMemberProfile() {
    const token = localStorage.getItem('member_token');
    const response = await api.get('/members/profile', {
      headers: { Authorization: `Bearer ${token}` },
    });
    return response.data;
  },

  // Update member profile
  async updateMemberProfile(data: { name?: string; avatar_url?: string; password?: string }) {
    const token = localStorage.getItem('member_token');
    const response = await api.put('/members/profile', data, {
      headers: { Authorization: `Bearer ${token}` },
    });
    return response.data;
  },

  // Get certificate
  async getCertificate(courseId: string) {
    const token = localStorage.getItem('member_token');
    const response = await api.get(`/members/courses/${courseId}/certificate`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    return response.data;
  },
};
