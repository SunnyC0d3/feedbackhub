import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from './context/AuthContext'
import ProtectedRoute from './components/ProtectedRoute'
import Layout from './components/Layout'
import LoginPage from './pages/LoginPage'
import DashboardPage from './pages/DashboardPage'
import DivisionsPage from './pages/DivisionsPage'
import DivisionDetailPage from './pages/DivisionDetailPage'
import ProjectsPage from './pages/ProjectsPage'
import ProjectDetailPage from './pages/ProjectDetailPage'
import FeedbackPage from './pages/FeedbackPage'
import FeedbackDetailPage from './pages/FeedbackDetailPage'
import CreateFeedbackPage from './pages/CreateFeedbackPage'
import AnalysisPage from './pages/AnalysisPage'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 30_000,
    },
  },
})

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route
              element={
                <ProtectedRoute>
                  <Layout />
                </ProtectedRoute>
              }
            >
              <Route path="/" element={<DashboardPage />} />
              <Route path="/divisions" element={<DivisionsPage />} />
              <Route path="/divisions/:id" element={<DivisionDetailPage />} />
              <Route path="/projects" element={<ProjectsPage />} />
              <Route path="/projects/:id" element={<ProjectDetailPage />} />
              <Route path="/feedback" element={<FeedbackPage />} />
              <Route path="/feedback/new" element={<CreateFeedbackPage />} />
              <Route path="/feedback/:id" element={<FeedbackDetailPage />} />
              <Route path="/analysis" element={<AnalysisPage />} />
            </Route>
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </BrowserRouter>
      </AuthProvider>
    </QueryClientProvider>
  )
}
