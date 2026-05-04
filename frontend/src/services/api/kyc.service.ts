import { api } from '@/lib/api';

export interface KycSubmission {
  id: string;
  status: 'pending' | 'under_review' | 'approved' | 'rejected';
  full_name: string;
  document_number: string;
  account_type: 'individual' | 'company';
  birth_date?: string;
  phone?: string;
  address_street?: string;
  address_number?: string;
  address_complement?: string;
  address_neighborhood?: string;
  address_city?: string;
  address_state?: string;
  address_zip_code?: string;
  company_name?: string;
  company_document?: string;
  created_at: string;
}

export interface KycDocumentStatus {
  label: string;
  uploaded: boolean;
  status: 'pending' | 'approved' | 'rejected' | 'not_uploaded';
  rejection_reason?: string;
}

export interface KycStatusResponse {
  success: boolean;
  data: {
    kyc_status: 'pending' | 'submitted' | 'under_review' | 'approved' | 'rejected';
    kyc_submitted_at?: string;
    kyc_approved_at?: string;
    rejection_reason?: string;
    submission?: KycSubmission;
    documents: Record<string, KycDocumentStatus>;
    can_submit: boolean;
  };
}

export interface KycSubmitData {
  full_name: string;
  birth_date: string;
  document_number: string;
  phone?: string;
  address_street: string;
  address_number: string;
  address_complement?: string;
  address_neighborhood: string;
  address_city: string;
  address_state: string;
  address_zip_code: string;
  account_type: 'individual' | 'company';
  company_name?: string;
  company_document?: string;
  pix_key?: string;
  pix_type?: string;
}

export const kycService = {
  /**
   * Get current KYC status
   */
  async getStatus(): Promise<KycStatusResponse> {
    const response = await api.get('/kyc/status');
    return response.data;
  },

  /**
   * Submit KYC personal data
   */
  async submit(data: KycSubmitData): Promise<{ success: boolean; data: { submission_id: string; message: string } }> {
    const response = await api.post('/kyc/submit', data);
    return response.data;
  },

  /**
   * Upload a document
   */
  async uploadDocument(documentType: string, file: File): Promise<{ success: boolean; data: { document_id: string } }> {
    const formData = new FormData();
    formData.append('document_type', documentType);
    formData.append('file', file);

    const response = await api.post('/kyc/documents', formData, {
      headers: {
        'Content-Type': undefined, // Let browser set multipart/form-data with boundary
      },
    });
    return response.data;
  },

  /**
   * Delete a document
   */
  async deleteDocument(documentId: string): Promise<{ success: boolean }> {
    const response = await api.delete(`/kyc/documents/${documentId}`);
    return response.data;
  },

  /**
   * Submit for review (final step)
   */
  async submitForReview(): Promise<{ success: boolean; data: { kyc_status: string; message: string } }> {
    const response = await api.post('/kyc/submit-for-review');
    return response.data;
  },
};
