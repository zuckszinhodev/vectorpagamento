import { create } from 'zustand';

interface TourState {
  isOpen: boolean;
  step: number;
  start: () => void;
  setStep: (step: number) => void;
  close: () => void;
}

export const useTourStore = create<TourState>((set) => ({
  isOpen: false,
  step: 0,
  start: () => set({ isOpen: true, step: 0 }),
  setStep: (step) => set({ step }),
  close: () => set({ isOpen: false, step: 0 }),
}));

const doneKey = (userId: string) => `gp_tour_done:${userId}`;

export function hasSeenTour(userId: string): boolean {
  try {
    return localStorage.getItem(doneKey(userId)) === '1';
  } catch {
    // Sem storage (aba anônima, bloqueio): não insiste em abrir o tour.
    return true;
  }
}

export function markTourSeen(userId: string): void {
  try {
    localStorage.setItem(doneKey(userId), '1');
  } catch {
    /* ignore */
  }
}
