import { Routes, Route, Navigate } from 'react-router-dom';
import { ProtectedRoute } from './auth/ProtectedRoute';
import { VendorDashboard } from './pages/VendorDashboard';
import { VendorListings } from './pages/VendorListings';
import { VendorListingForm } from './pages/VendorListingForm';

const VENDOR_ROLES = ['seller', 'manufacturer', 'importer', 'service_provider', 'supplier'];

export default function App() {
  return (
    <Routes>
      <Route
        path="/vendor"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorDashboard />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/listings/:type"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorListings />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/listings/:type/new"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorListingForm />
          </ProtectedRoute>
        }
      />
      <Route
        path="/vendor/listings/:type/:id/edit"
        element={
          <ProtectedRoute roles={VENDOR_ROLES}>
            <VendorListingForm />
          </ProtectedRoute>
        }
      />
      <Route path="*" element={<Navigate to="/vendor" replace />} />
    </Routes>
  );
}
