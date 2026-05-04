interface GoogleAccountsId {
  initialize: (config: { client_id: string; callback: (response: { credential: string }) => void }) => void;
  prompt: () => void;
  renderButton: (element: HTMLElement, config: Record<string, unknown>) => void;
}

interface Window {
  google?: {
    accounts: {
      id: GoogleAccountsId;
    };
  };
}
